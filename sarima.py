# %% SARIMA Forecast with Backtest
import pandas as pd
import numpy as np
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
from statsmodels.tsa.statespace.sarimax import SARIMAX
import warnings
warnings.filterwarnings("ignore")

# ==== Load dataset ====
df = pd.read_excel("mscookiesWHOLE.xlsx", sheet_name="Sheet1")
df["DATE"] = pd.to_datetime(df["DATE"], errors="coerce")
df["SALES"] = df["PRICE"].astype(float)

# ==== Aggregate monthly sales ====
monthly_sales = df.groupby(pd.Grouper(key="DATE", freq="M"))["SALES"].sum()

# ==== Define backtest range ====
start_date = "2021-10-20"
end_date = monthly_sales.index.max()
test_series = monthly_sales.loc[start_date:end_date]

# ==== Backtest SARIMA ====
results = []
for i in range(1, len(test_series)):   # start at 1 so we have train before test
    train = test_series[:i]            # train up to i-1
    test_month = test_series.index[i]
    actual_value = test_series.iloc[i]

    if len(train) < 3:
        forecast = train.mean() if len(train) > 0 else actual_value
    else:
        try:
            model = SARIMAX(
                train,
                order=(0, 1, 1),
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
def compute_metrics(y_true, y_pred, reached_col=None):
    epsilon = 1e-10
    y_true = np.array(y_true)
    y_pred = np.array(y_pred)
    mask = y_true > 0
    mape = np.mean(np.abs((y_true[mask] - y_pred[mask]) / y_true[mask])) * 100 if mask.sum() > 0 else np.nan
    accuracy = 100 - mape
    mae = mean_absolute_error(y_true, y_pred)
    rmse = np.sqrt(mean_squared_error(y_true, y_pred))
    nrmse = rmse / (y_true.max() - y_true.min() + epsilon)
    avg_rmse = rmse / len(y_true)
    r2 = r2_score(y_true, y_pred)
    n = len(y_true); k = 5
    adj_r2 = 1 - (1 - r2) * (n - 1) / (n - k - 1)
    rss = np.sum((y_true - y_pred) ** 2)
    bic = k * np.log(n) + n * np.log(rss / n + epsilon)
    reached_acc = (reached_col == "Yes").mean() * 100 if reached_col is not None else np.nan
    return [mae, rmse, nrmse, avg_rmse, mape, accuracy,
            reached_acc, bic, 0, r2, adj_r2]

metrics_df = pd.DataFrame({
    "Metric": ["MAE","RMSE","NRMSE","Avg RMSE","MAPE","Accuracy (%)",
               "Reached Accuracy (%)","BIC","Normalized BIC","R-squared","Adjusted R-squared"],
    "SARIMA(0,1,1)(0,0,1,12)": compute_metrics(
        all_months["Actual_Sales"], all_months["Forecasted_Sales"], all_months["Reached?"]
    )
})

# ==== 12-Month Future Forecast ====
sarima_model = SARIMAX(
    test_series,   # fit only from 2021-10 onwards
    order=(0, 1, 1),
    seasonal_order=(0, 0, 1, 12),
    enforce_stationarity=False,
    enforce_invertibility=False
)
sarima_fit = sarima_model.fit(disp=False)
future_forecast = sarima_fit.forecast(12)
future_dates = pd.date_range(
    start=test_series.index[-1] + pd.offsets.MonthBegin(1),
    periods=12, freq="M"
)
future_df = pd.DataFrame({
    "DATE": future_dates,
    "Forecasted_Sales": future_forecast.round(2)
})

# ==== Output ====
print("\n=== Metrics (Backtest from 2021-10–2025) ===")
print(metrics_df.round(2))

print("\n=== Backtest Results ===")
print(all_months.round(2).to_string(index=False))

print("\n=== 12-Month Future Forecast ===")
print(future_df.to_string(index=False))
