# %%
import pandas as pd
import numpy as np
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
from statsmodels.tsa.statespace.sarimax import SARIMAX
import warnings
warnings.filterwarnings("ignore")

# ==== Load dataset ====
df = pd.read_excel("mscookiesWHOLE.xlsx", sheet_name="Sheet1")
df["DATE"] = pd.to_datetime(df["DATE"], errors="coerce")
df["SALES"] = df["PRICE"].astype(float)  # Or QUANTITY * PRICE if you want revenue

# ==== Aggregate monthly sales ====
monthly_sales = df.groupby(pd.Grouper(key="DATE", freq="M"))["SALES"].sum()

# ==== Define backtest range (2021 until last available month) ====
start_date = "2021-01-01"
end_date = monthly_sales.index.max()  # last month in Excel
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

# ==== Metrics ====
y_true = all_months["Actual_Sales"].values
y_pred = all_months["Forecasted_Sales"].values

epsilon = 1e-10
y_true_safe = np.where(y_true == 0, epsilon, y_true)

mae = mean_absolute_error(y_true, y_pred)
rmse = np.sqrt(mean_squared_error(y_true, y_pred))
nrmse = rmse / (y_true.max() - y_true.min())
avg_rmse = rmse / len(y_true)
mape = np.mean(np.abs((y_true - y_pred) / y_true_safe)) * 100
accuracy = 100 - mape
reached_accuracy = (all_months["Reached?"] == "Yes").mean() * 100

# ==== BIC ====
n = len(y_true)
rss = np.sum((y_true - y_pred) ** 2)
k = 5
bic = k * np.log(n) + n * np.log(rss / n)
bic_min, bic_max = bic, bic
norm_bic = 0

# ==== R² and Adjusted R² ====
r2 = r2_score(y_true, y_pred)
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

# ==== 12-Month Future Forecast (using full data up to last available) ====
sarima_model = SARIMAX(
    monthly_sales,
    order=(0, 1, 0),
    seasonal_order=(0, 0, 1, 12),
    enforce_stationarity=False,
    enforce_invertibility=False
)
sarima_fit = sarima_model.fit(disp=False)

future_forecast = sarima_fit.forecast(12)
future_dates = pd.date_range(start=monthly_sales.index[-1] + pd.offsets.MonthBegin(1), periods=12, freq="M")

future_df = pd.DataFrame({
    "DATE": future_dates,
    "Forecasted_Sales": future_forecast.values
})

# ==== Output ====
print("\n=== Metrics (Backtest from 2021–2025) ===")
print(metrics_df)

print("\n=== Backtest Results (sample) ===")
print(all_months.to_string(index=False)) # preview first 15 test months

print("\n=== 12-Month Future Forecast ===")
print(future_df.to_string(index=False))








from sklearn.preprocessing import PolynomialFeatures
from sklearn.linear_model import LinearRegression

# === Residuals from SARIMA Backtest ===
all_months["Residuals"] = all_months["Actual_Sales"] - all_months["Forecasted_Sales"]

# Use month index as numeric feature
X_time = np.arange(len(all_months)).reshape(-1, 1)
y_resid = all_months["Residuals"].values

# Polynomial regression (degree=2, can tune to 3 if needed)
poly = PolynomialFeatures(degree=2)
X_poly = poly.fit_transform(X_time)

poly_model = LinearRegression()
poly_model.fit(X_poly, y_resid)

# Predict residual corrections
resid_pred = poly_model.predict(X_poly)

# Corrected forecasts
all_months["Hybrid_Forecast"] = all_months["Forecasted_Sales"] + resid_pred

# ==== Hybrid Metrics ====
y_pred_hybrid = all_months["Hybrid_Forecast"].values

mae_h = mean_absolute_error(y_true, y_pred_hybrid)
rmse_h = np.sqrt(mean_squared_error(y_true, y_pred_hybrid))
nrmse_h = rmse_h / (y_true.max() - y_true.min())
avg_rmse_h = rmse_h / len(y_true)
mape_h = np.mean(np.abs((y_true - y_pred_hybrid) / y_true_safe)) * 100
accuracy_h = 100 - mape_h
r2_h = r2_score(y_true, y_pred_hybrid)
adj_r2_h = 1 - (1 - r2_h) * (n - 1) / (n - k - 1)

metrics_hybrid = pd.DataFrame({
    "Metric": ["MAE", "RMSE", "NRMSE", "Avg RMSE", "MAPE", "Accuracy (%)", "R-squared", "Adjusted R-squared"],
    "SARIMA": [mae, rmse, nrmse, avg_rmse, mape, accuracy, r2, adj_r2],
    "SARIMA + Polynomial": [mae_h, rmse_h, nrmse_h, avg_rmse_h, mape_h, accuracy_h, r2_h, adj_r2_h]
})

print("\n=== SARIMA vs SARIMA+Polynomial Regression (Backtest 2021–2025) ===")
print(metrics_hybrid)
