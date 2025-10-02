# %%
import pandas as pd
import numpy as np
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
from statsmodels.tsa.statespace.sarimax import SARIMAX
from sklearn.neighbors import KNeighborsRegressor
import warnings
warnings.filterwarnings("ignore")

# ==== Load dataset ====
df = pd.read_excel("mscookiesWHOLE.xlsx", sheet_name="Sheet1")
df["DATE"] = pd.to_datetime(df["DATE"], errors="coerce")
df["SALES"] = df["PRICE"].astype(float)

# ==== Aggregate monthly sales ====
monthly_sales = df.groupby(pd.Grouper(key="DATE", freq="M"))["SALES"].sum()

# ==== Define backtest range (2021 until last available month) ====
start_date = "2021-01-01"
end_date = monthly_sales.index.max()
test_series = monthly_sales.loc[start_date:end_date]

results = []

for i in range(len(test_series)):
    train = monthly_sales.loc[: test_series.index[i]].iloc[:-1]
    test_month = test_series.index[i]
    actual_value = test_series.iloc[i]

    if len(train) < 3:
        forecast = train.mean() if len(train) > 0 else actual_value
    else:
        try:
            model = SARIMAX(train, order=(0, 1, 0), seasonal_order=(0, 0, 1, 12),
                            enforce_stationarity=False, enforce_invertibility=False)
            fit = model.fit(disp=False)
            forecast = fit.forecast(1)[0]
        except:
            forecast = train.mean()

    results.append({
        "DATE": test_month,
        "Forecasted_Sales": forecast,
        "Actual_Sales": actual_value
    })

all_months = pd.DataFrame(results)

# ==== Residual Modeling with KNN ====
all_months["Residuals"] = all_months["Actual_Sales"] - all_months["Forecasted_Sales"]
X_time = np.arange(len(all_months)).reshape(-1, 1)
y_resid = all_months["Residuals"].values

knn = KNeighborsRegressor(n_neighbors=5)
knn.fit(X_time, y_resid)
resid_pred = knn.predict(X_time)

all_months["Hybrid_Forecast"] = all_months["Forecasted_Sales"] + resid_pred
all_months["Difference"] = all_months["Actual_Sales"] - all_months["Hybrid_Forecast"]
all_months["Reached?"] = np.where(all_months["Actual_Sales"] >= all_months["Hybrid_Forecast"], "Yes", "No")

# ==== Metrics ====
def compute_metrics(y_true, y_pred, reached_col=None):
    epsilon = 1e-10
    y_true_safe = np.where(y_true == 0, epsilon, y_true)
    mae = mean_absolute_error(y_true, y_pred)
    rmse = np.sqrt(mean_squared_error(y_true, y_pred))
    nrmse = rmse / (y_true.max() - y_true.min())
    avg_rmse = rmse / len(y_true)
    mape = np.mean(np.abs((y_true - y_pred) / y_true_safe)) * 100
    accuracy = 100 - mape
    n = len(y_true); k = 5
    r2 = r2_score(y_true, y_pred)
    adj_r2 = 1 - (1 - r2) * (n - 1) / (n - k - 1)
    rss = np.sum((y_true - y_pred) ** 2)
    bic = k * np.log(n) + n * np.log(rss / n)
    reached_acc = (reached_col == "Yes").mean() * 100 if reached_col is not None else np.nan
    return [mae, rmse, nrmse, avg_rmse, mape, accuracy, reached_acc, bic, 0, r2, adj_r2]

metrics_df = pd.DataFrame({
    "Metric": ["MAE","RMSE","NRMSE","Avg RMSE","MAPE","Accuracy (%)",
               "Reached Accuracy (%)","BIC","Normalized BIC","R-squared","Adjusted R-squared"],
    "SARIMA": compute_metrics(all_months["Actual_Sales"], all_months["Forecasted_Sales"]),
    "SARIMA + KNN": compute_metrics(all_months["Actual_Sales"], all_months["Hybrid_Forecast"], all_months["Reached?"])
})

# ==== 12-Month Future Forecast (Hybrid) ====
sarima_model = SARIMAX(monthly_sales, order=(0,1,0), seasonal_order=(0,0,1,12),
                       enforce_stationarity=False, enforce_invertibility=False)
sarima_fit = sarima_model.fit(disp=False)
future_forecast = sarima_fit.forecast(12)

future_dates = pd.date_range(start=monthly_sales.index[-1] + pd.offsets.MonthBegin(1),
                             periods=12, freq="M")

# Correct SARIMA future with KNN residual model
future_X = np.arange(len(all_months), len(all_months)+12).reshape(-1,1)
future_resid_pred = knn.predict(future_X)
future_hybrid = future_forecast.values + future_resid_pred

future_df = pd.DataFrame({
    "DATE": future_dates,
    "SARIMA_Forecast": future_forecast.values,
    "Hybrid_Forecast": future_hybrid
})

# ==== Output ====
print("\n=== Metrics (Backtest from 2021–2025) ===")
print(metrics_df)

print("\n=== Backtest Results ===")
print(all_months.to_string(index=False))

print("\n=== 12-Month Future Forecast (Hybrid) ===")
print(future_df.to_string(index=False))
