# %% extended_sarima_with_ml.py
import pandas as pd
import numpy as np
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
from sklearn.linear_model import LinearRegression, Ridge, Lasso
from sklearn.preprocessing import PolynomialFeatures
from sklearn.pipeline import make_pipeline
from sklearn.tree import DecisionTreeRegressor
from sklearn.ensemble import RandomForestRegressor, GradientBoostingRegressor
from sklearn.neighbors import KNeighborsRegressor
from sklearn.svm import SVR
from sklearn.naive_bayes import GaussianNB
from statsmodels.tsa.statespace.sarimax import SARIMAX
import warnings
warnings.filterwarnings("ignore")

# ==== Load dataset ====
df = pd.read_excel("mscookiesWHOLE.xlsx", sheet_name="Sheet1")
df["DATE"] = pd.to_datetime(df["DATE"], errors="coerce")
df["SALES"] = df["PRICE"].astype(float)  # Or QUANTITY * PRICE if you want revenue

# ==== Aggregate monthly sales ====
monthly_sales = df.groupby(pd.Grouper(key="DATE", freq="M"))["SALES"].sum()

# ==== (A) Original baseline SARIMA backtest (exactly your code) ====
start_date = "2021-01-01"
end_date = monthly_sales.index.max()
test_series = monthly_sales.loc[start_date:end_date]

results = []
for i in range(len(test_series)):
    train = monthly_sales.loc[: test_series.index[i]].iloc[:-1]  # history up to previous month
    test_month = test_series.index[i]
    actual_value = test_series.iloc[i]

    if len(train) < 3:  # need at least some history
        forecast = train.mean() if len(train) > 0 else actual_value
    else:
        try:
            model = SARIMAX(
                train,
                order=(0, 1, 0),
                seasonal_order=(0, 0, 1, 12),
                enforce_stationarity=False,
                enforce_invertibility=False
            )
            fit = model.fit(disp=False)
            forecast = fit.forecast(1)[0]
        except:
            forecast = train.mean()

    reached = "Yes" if actual_value >= forecast else "No"

    results.append({
        "DATE": test_month,
        "Forecasted_Sales": forecast,
        "Actual_Sales": actual_value,
        "Difference": actual_value - forecast,
        "Reached?": reached
    })

all_months = pd.DataFrame(results)

# ==== Baseline metrics (exact same formulas you used) ====
y_true_full = all_months["Actual_Sales"].values
y_pred_full = all_months["Forecasted_Sales"].values

epsilon = 1e-10
y_true_safe = np.where(y_true_full == 0, epsilon, y_true_full)

mae = mean_absolute_error(y_true_full, y_pred_full)
rmse = np.sqrt(mean_squared_error(y_true_full, y_pred_full))
nrmse = rmse / (y_true_full.max() - y_true_full.min())
avg_rmse = rmse / len(y_true_full)
mape = np.mean(np.abs((y_true_full - y_pred_full) / y_true_safe)) * 100
accuracy = 100 - mape
reached_accuracy = (all_months["Reached?"] == "Yes").mean() * 100

n = len(y_true_full)
rss = np.sum((y_true_full - y_pred_full) ** 2)
k = 5
bic = k * np.log(n) + n * np.log(rss / n)
norm_bic = 0

r2 = r2_score(y_true_full, y_pred_full)
adj_r2 = 1 - (1 - r2) * (n - 1) / (n - k - 1)

metrics_df = pd.DataFrame({
    "Metric": [
        "MAE", "RMSE", "NRMSE", "Avg RMSE",
        "MAPE", "Accuracy (%)", "Reached Accuracy (%)",
        "BIC", "Normalized BIC", "R-squared", "Adjusted R-squared"
    ],
    "Value": [
        mae, rmse, nrmse, avg_rmse,
        mape, accuracy, reached_accuracy,
        bic, norm_bic, r2, adj_r2
    ]
})

# ==== 12-Month Future Forecast (SARIMA full) ====
sarima_model = SARIMAX(
    monthly_sales,
    order=(0, 1, 0),
    seasonal_order=(0, 0, 1, 12),
    enforce_stationarity=False,
    enforce_invertibility=False
)
sarima_fit = sarima_model.fit(disp=False)
future_forecast = sarima_fit.forecast(12)
future_dates = pd.date_range(start=monthly_sales.index[-1] + pd.offsets.MonthBegin(1),
                             periods=12, freq="M")
future_df = pd.DataFrame({
    "DATE": future_dates,
    "Forecasted_Sales": future_forecast.values
})

# ---- Print baseline in user format ----
print("\n=== Metrics (Backtest from 2021–2025) ===")
print(metrics_df.to_string(index=False))

print("\n=== Backtest Results (sample) ===")
print(all_months.to_string(index=False))  # prints all backtest rows

print("\n=== 12-Month Future Forecast (SARIMA) ===")
print(future_df.to_string(index=False))

# ==== (B) Prepare data for ML hybrids (use same backtest to get y_true and sarima preds) ====
# We'll reconstruct y_true (actuals) and sarima predictions in-sample with the same backtest loop.
sarima_preds, actuals = [], []
for i in range(len(test_series)):
    train = monthly_sales.loc[: test_series.index[i]].iloc[:-1]
    actual = test_series.iloc[i]
    if len(train) < 3:
        pred = train.mean() if len(train) > 0 else actual
    else:
        try:
            m = SARIMAX(train, order=(0, 1, 0), seasonal_order=(0, 0, 1, 12),
                        enforce_stationarity=False, enforce_invertibility=False)
            fitm = m.fit(disp=False)
            pred = fitm.forecast(1)[0]
        except:
            pred = train.mean()
    sarima_preds.append(pred)
    actuals.append(actual)

y_true = np.array(actuals)
y_sarima = np.array(sarima_preds)

# Choose lag
lag = 3
# Build ML training features and residual target aligned so that X[t] predicts residual at t
X_list, y_resid = [], []
for t in range(lag, len(y_sarima)):
    # features = previous actuals + previous sarima forecasts (you can tweak)
    prev_actuals = list(y_true[t - lag:t])       # actuals t-lag ... t-1
    prev_sarima = [y_sarima[t - 1]]              # last sarima forecast
    X_list.append(prev_actuals + prev_sarima)
    y_resid.append(y_true[t] - y_sarima[t])      # residual at t

X = np.array(X_list)
y = np.array(y_resid)

if len(X) == 0:
    raise SystemExit("Not enough data to build ML features (increase dataset or reduce lag).")

# ==== ML models to test ====
from sklearn.pipeline import make_pipeline
models = {
    "Linear Regression": LinearRegression(),
    "Ridge Regression": Ridge(),
    "Lasso Regression": Lasso(),
    "Polynomial Regression": make_pipeline(PolynomialFeatures(2, include_bias=False), LinearRegression()),
    "Decision Tree": DecisionTreeRegressor(random_state=42),
    "Random Forest": RandomForestRegressor(n_estimators=200, random_state=42),
    "Gradient Boosting": GradientBoostingRegressor(n_estimators=200, random_state=42),
    "KNN": KNeighborsRegressor(n_neighbors=3),
    "SVM (SVR)": SVR(),
    # Naive Bayes is not ideal for regression; we'll discretize residual sign and map back to mean residual magnitude
    "Naive Bayes (sign-based)": GaussianNB()
}

# Helper: compute metrics exactly like baseline, align samples (we compared y_true[lag:] with y_pred_ml)
def compute_metrics_arr(y_true_arr, y_pred_arr):
    epsilon = 1e-10
    y_true_safe = np.where(y_true_arr == 0, epsilon, y_true_arr)
    mae = mean_absolute_error(y_true_arr, y_pred_arr)
    rmse = np.sqrt(mean_squared_error(y_true_arr, y_pred_arr))
    nrmse = rmse / (y_true_arr.max() - y_true_arr.min()) if (y_true_arr.max() - y_true_arr.min()) != 0 else np.nan
    avg_rmse = rmse / len(y_true_arr)
    mape = np.mean(np.abs((y_true_arr - y_pred_arr) / y_true_safe)) * 100
    accuracy = 100 - mape
    reached_accuracy = np.mean(y_true_arr >= y_pred_arr) * 100

    n = len(y_true_arr)
    rss = np.sum((y_true_arr - y_pred_arr) ** 2)
    k = 5
    bic = k * np.log(n) + n * np.log(rss / n) if rss > 0 else np.nan
    norm_bic = 0
    r2 = r2_score(y_true_arr, y_pred_arr)
    adj_r2 = 1 - (1 - r2) * (n - 1) / (n - k - 1)
    return {
        "MAE": mae, "RMSE": rmse, "NRMSE": nrmse, "Avg RMSE": avg_rmse,
        "MAPE": mape, "Accuracy (%)": accuracy, "Reached Accuracy (%)": reached_accuracy,
        "BIC": bic, "Normalized BIC": norm_bic, "R-squared": r2, "Adjusted R-squared": adj_r2
    }

# We'll store results in list of dicts (model name + metrics)
comparison = []

# Add baseline computed on same sample used by ML (so fair comparison): y_sarima[lag:] vs y_true[lag:]
baseline_subset_metrics = compute_metrics_arr(y_true[lag:], y_sarima[lag:])
baseline_subset_metrics["Model"] = "SARIMA Baseline (aligned)"
comparison.append(baseline_subset_metrics)

# Fit each ML model to residuals and evaluate (in-sample)
for name, model in models.items():
    try:
        if name == "Naive Bayes (sign-based)":
            # Train NB on sign of residuals (positive/negative)
            y_sign = (y > 0).astype(int)
            model.fit(X, y_sign)
            preds_cls = model.predict(X)  # 0/1
            # Map 1 -> mean positive residual, 0 -> mean negative residual
            mean_pos = y[y > 0].mean() if np.any(y > 0) else 0.0
            mean_neg = y[y <= 0].mean() if np.any(y <= 0) else 0.0
            resid_pred = np.where(preds_cls == 1, mean_pos, mean_neg)
        else:
            model.fit(X, y)
            resid_pred = model.predict(X)

        # Final hybrid prediction aligned to y_true[lag:]
        y_pred_hybrid = y_sarima[lag:] + resid_pred

        m = compute_metrics_arr(y_true[lag:], y_pred_hybrid)
        m["Model"] = f"SARIMA + {name}"
        comparison.append(m)
    except Exception as e:
        print(f"Skipped {name} due to error: {e}")

# Present comparison as DataFrame (same metric names / order as baseline)
metrics_order = ["Model", "MAE", "RMSE", "NRMSE", "Avg RMSE",
                 "MAPE", "Accuracy (%)", "Reached Accuracy (%)",
                 "BIC", "Normalized BIC", "R-squared", "Adjusted R-squared"]

comparison_df = pd.DataFrame(comparison)[metrics_order]

print("\n=== SARIMA (aligned) vs SARIMA+ML Hybrids (Backtest from 2021–2025) ===")
print(comparison_df.to_string(index=False))

# Optionally: 12-month future forecasts for hybrids (use last available train window)
# We'll produce 12-month SARIMA forecast (you already have 'future_df').
# For each ML hybrid we can compute a naive hybrid 12-month forecast by:
#  - forecasting SARIMA 12 months (future_forecast)
#  - predicting residual corrections using the ML model trained on X (requires crafting features for future months)
# This is dataset-specific and more complex; ask me if you want that too.
