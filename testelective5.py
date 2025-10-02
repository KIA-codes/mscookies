import pandas as pd
import numpy as np
import matplotlib.pyplot as plt
from statsmodels.tsa.statespace.sarimax import SARIMAX
from statsmodels.tsa.stattools import adfuller
from sklearn.metrics import mean_squared_error, mean_absolute_error

# === 1. Load & Clean Data ===
df = pd.read_csv("MSCOOKIES FULL.csv")
df.columns = df.columns.str.strip().str.upper()
df["DATE"] = pd.to_datetime(df["DATE"].astype(str), errors="coerce", infer_datetime_format=True)
df["PRICE"] = pd.to_numeric(df["PRICE"].astype(str).str.replace(r"[^\d.]", "", regex=True), errors="coerce")
df = df.dropna(subset=["DATE", "PRICE"])

# === 2. Resample Monthly Sales ===
monthly = df.set_index("DATE")["PRICE"].resample("M").sum()
monthly = monthly[(monthly.index >= "2023-01-01") & (monthly.index <= "2025-12-31") & (monthly > 0)]

print("=== Monthly Sales (2023–2025, Non-zero) ===")
print(monthly)

# === 3. Stationarity Check (ADF Test) ===
result = adfuller(monthly)
print(f"\nADF Statistic: {result[0]:.4f}")
print(f"p-value: {result[1]:.4f}")
print("Stationary" if result[1] < 0.05 else "Non-Stationary")

# === 4. Fit SARIMA ===
model = SARIMAX(monthly, order=(1,1,1), seasonal_order=(1,1,1,12),
                enforce_stationarity=False, enforce_invertibility=False)
results = model.fit(disp=False)

# === 5. Forecast Next 3 Months ===
steps = 3
forecast = results.get_forecast(steps=steps)
forecast_mean = forecast.predicted_mean.clip(lower=0)  # 🔥 Replace negatives with 0
forecast_ci = forecast.conf_int()
forecast_mean.index = pd.date_range(start=monthly.index[-1] + pd.offsets.MonthBegin(),
                                    periods=steps, freq="M")

# === 6. Actual vs Predicted (In-sample + Forecast) ===
fitted = results.fittedvalues.clip(lower=0)  # 🔥 Clip fitted values to 0 too
combined = pd.DataFrame({
    "Actual": monthly,
    "Predicted": fitted
})
combined["Adjusted Actual"] = combined[["Actual", "Predicted"]].max(axis=1)

forecast_df = pd.DataFrame({
    "Actual": [None]*steps,
    "Predicted": forecast_mean,
    "Adjusted Actual": forecast_mean
})
combined = pd.concat([combined, forecast_df])

# === 7. Add Reached? Column ===
def check_reached(row):
    if pd.isna(row["Actual"]):  # Forecasted months
        return "Forecast"
    return "Yes" if row["Actual"] >= row["Predicted"] else "No"

combined["Reached?"] = combined.apply(check_reached, axis=1)

# === 8. Display Metrics ===
print("\n=== Forecast for Next 3 Months (No Negative Sales) ===")
print(forecast_df.round(2))

test_actual = monthly.iloc[-steps:]
test_pred = fitted.iloc[-steps:]
rmse = np.sqrt(mean_squared_error(test_actual, test_pred))
mae = mean_absolute_error(test_actual, test_pred)
mape = np.mean(np.abs((test_actual - test_pred) / test_actual)) * 100
print(f"\nRMSE: {rmse:.2f}, MAE: {mae:.2f}, MAPE: {mape:.2f}%")

# === 9. Show Verification Table ===
print("\n=== Actual vs Predicted with Reached Status ===")
print(combined[["Actual", "Predicted", "Reached?"]].round(2))

# === 10. Plot Line Graph ===
plt.figure(figsize=(12,6))
plt.plot(combined.index, combined["Adjusted Actual"], label="Observed / Adjusted Actual", color="blue")
plt.plot(combined.index, combined["Predicted"], label="Predicted / Forecast", color="red", linestyle="--")
plt.fill_between(forecast_ci.index,
                 forecast_ci.iloc[:, 0].clip(lower=0),
                 forecast_ci.iloc[:, 1].clip(lower=0),
                 color="red", alpha=0.3, label="95% Forecast Interval")

# Mark forecasted months on chart
for fc_date in forecast_mean.index:
    plt.axvline(fc_date, color="gray", linestyle=":", alpha=0.6)
    plt.text(fc_date, plt.ylim()[1]*0.9, "Forecast", rotation=90,
             verticalalignment="top", horizontalalignment="center", fontsize=8, color="black")

plt.xlim(combined.index.min(), combined.index.max())
plt.title("Monthly Sales & SARIMA Forecast (Next 3 Months, No Negative Sales)", fontsize=14, fontweight="bold")
plt.xlabel("Date")
plt.ylabel("Sales (₱)")
plt.legend()
plt.grid(alpha=0.3)
plt.tight_layout()
plt.show()

from sklearn.metrics import mean_absolute_error, mean_squared_error

# Drop forecast rows with None (since no actual value to compare)
eval_df = results_df.dropna(subset=["Actual"])

# Error metrics
mae = mean_absolute_error(eval_df["Actual"], eval_df["Predicted"])
rmse = mean_squared_error(eval_df["Actual"], eval_df["Predicted"], squared=False)
mape = (abs((eval_df["Actual"] - eval_df["Predicted"]) / eval_df["Actual"])).mean() * 100
accuracy = 100 - mape

print("\n=== Prediction Accuracy Metrics ===")
print(f"MAE  : {mae:.2f}")
print(f"RMSE : {rmse:.2f}")
print(f"MAPE : {mape:.2f}%")
print(f"Overall Accuracy: {accuracy:.2f}%")