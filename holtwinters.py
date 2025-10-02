# %%
import pandas as pd
import numpy as np
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
from statsmodels.tsa.holtwinters import ExponentialSmoothing
import warnings
warnings.filterwarnings("ignore")

# ==== Load dataset ====
df = pd.read_excel("mscookiesWHOLE.xlsx", sheet_name="Sheet1")
df["DATE"] = pd.to_datetime(df["DATE"], errors="coerce")
df["SALES"] = df["PRICE"].astype(float)  # Or QUANTITY * PRICE if you want revenue

# ==== Aggregate monthly sales ====
monthly_sales = df.groupby(pd.Grouper(key="DATE", freq="M"))["SALES"].sum()

# ==== Rolling one-step-ahead forecast (all months) ====
results = []

for i in range(len(monthly_sales)):
    train = monthly_sales.iloc[:i]  # all previous months
    test_month = monthly_sales.index[i]
    actual_value = monthly_sales.iloc[i]

    # Always produce a forecast
    if i == 0:
        forecast = actual_value  # first month: use actual
    elif i < 12:
        forecast = train.mean()  # first 12 months: mean of available
    else:
        try:
            model = ExponentialSmoothing(train, seasonal="add", seasonal_periods=12)
            fit = model.fit()
            forecast = fit.forecast(1)[0]
        except:
            forecast = train.mean()  # fallback if HW fails

    reached = "Yes" if actual_value >= forecast else "No"

    results.append({
        "DATE": test_month,
        "Forecasted_Sales": forecast,
        "Actual_Sales": actual_value,
        "Difference": actual_value - forecast,
        "Reached?": reached
    })

all_months = pd.DataFrame(results)

# ==== Metrics (only for months with forecasts) ====
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

# ==== BIC & Normalized BIC ====
n = len(y_true)
rss = np.sum((y_true - y_pred) ** 2)
k = 3  # Holt-Winters usually has 3 main parameters (level, trend, seasonality)
bic = k * np.log(n) + n * np.log(rss / n)

# Normalized BIC (scale 0-1 relative to this run, for comparisons add multiple models)
bic_min, bic_max = bic, bic  # if only one model
norm_bic = 0  # with one model, normalized BIC is trivially 0

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

# ==== 12-Month Future Forecast ====
ets_model = ExponentialSmoothing(monthly_sales, seasonal="add", seasonal_periods=12)
ets_fit = ets_model.fit()
future_forecast = ets_fit.forecast(12)
future_dates = pd.date_range(start=monthly_sales.index[-1] + pd.offsets.MonthBegin(1), periods=12, freq='M')

future_df = pd.DataFrame({
    "DATE": future_dates,
    "Forecasted_Sales": future_forecast.values
})

# ==== Preview ====
#print("=== Backtest Results for All Months ===")
#print(all_months.to_string(index=False))

print("\n=== Metrics ===")
print(metrics_df)

print("\n=== 12-Month Future Forecast ===")
print(future_df.to_string(index=False))
