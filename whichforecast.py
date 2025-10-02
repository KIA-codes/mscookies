# %% arima_sarima_holtwinters_comparison.py
import pandas as pd
import numpy as np
from statsmodels.tsa.arima.model import ARIMA
from statsmodels.tsa.holtwinters import ExponentialSmoothing
from statsmodels.tsa.statespace.sarimax import SARIMAX
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
import warnings
warnings.filterwarnings("ignore")

# ==== Load dataset ====
df = pd.read_excel("mscookiesWHOLE.xlsx", sheet_name="Sheet1")
df["DATE"] = pd.to_datetime(df["DATE"], errors="coerce")
df["SALES"] = df["PRICE"].astype(float)

# ==== Aggregate monthly sales ====
monthly_sales = df.groupby(pd.Grouper(key="DATE", freq="M"))["SALES"].sum()

# ==== Backtest range ====
start_date = "2021-10-20"
end_date = monthly_sales.index.max()
test_series = monthly_sales.loc[start_date:end_date]

# ==== Metric function (fixed) ====
def compute_metrics(y_true, y_pred, k=5):
    mae = mean_absolute_error(y_true, y_pred)
    rmse = np.sqrt(mean_squared_error(y_true, y_pred))
    nrmse = rmse / (y_true.max() - y_true.min()) if (y_true.max() - y_true.min()) != 0 else np.nan
    
    # avoid division by zero for MAPE
    mask = y_true != 0
    if mask.sum() > 0:
        mape = np.mean(np.abs((y_true[mask] - y_pred[mask]) / y_true[mask])) * 100
        accuracy = 100 - mape
    else:
        mape, accuracy = np.nan, np.nan

    r2 = r2_score(y_true, y_pred)
    n = len(y_true)
    rss = np.sum((y_true - y_pred) ** 2)
    bic = k * np.log(n) + n * np.log(rss / n) if rss > 0 else np.nan
    adj_r2 = 1 - (1 - r2) * (n - 1) / (n - k - 1)
    return mae, rmse, nrmse, mape, accuracy, r2, adj_r2, bic

# ==== Backtesting helper ====
def rolling_forecast(model_func, order=None, seasonal_order=None, hw_params=None):
    preds, actuals = [], []
    for i in range(len(test_series)):
        train = monthly_sales.loc[: test_series.index[i]].iloc[:-1]
        actual_value = test_series.iloc[i]

        if len(train) < 3:
            forecast = train.mean() if len(train) > 0 else actual_value
        else:
            try:
                if model_func == "ARIMA":
                    model = ARIMA(train, order=order).fit()
                    forecast = model.forecast(1)[0]
                elif model_func == "SARIMA":
                    model = SARIMAX(train, order=order, seasonal_order=seasonal_order,
                                    enforce_stationarity=False, enforce_invertibility=False).fit(disp=False)
                    forecast = model.forecast(1)[0]
                elif model_func == "HoltWinters":
                    model = ExponentialSmoothing(train, **hw_params).fit()
                    forecast = model.forecast(1)[0]
                else:
                    forecast = train.mean()
            except:
                forecast = train.mean()

        preds.append(forecast)
        actuals.append(actual_value)

    return np.array(actuals), np.array(preds)

# ==== Run models ====
comparison = []

# Holt-Winters
y_true, y_pred = rolling_forecast(
    "HoltWinters",
    hw_params={"seasonal": "add", "seasonal_periods": 12, "trend": "add"}
)
mae, rmse, nrmse, mape, acc, r2, adj_r2, bic = compute_metrics(y_true, y_pred)
comparison.append({
    "Model": "Holt-Winters",
    "MAE": round(mae,2), "RMSE": round(rmse,2), "NRMSE": round(nrmse,4),
    "MAPE": round(mape,2), "Accuracy (%)": round(acc,2),
    "R-squared": round(r2,3), "Adjusted R-squared": round(adj_r2,3),
    "BIC": round(bic,2)
})

# ARIMA(0,1,1)
y_true, y_pred = rolling_forecast("ARIMA", order=(0,1,1))
mae, rmse, nrmse, mape, acc, r2, adj_r2, bic = compute_metrics(y_true, y_pred)
comparison.append({
    "Model": "ARIMA(0,1,1)",
    "MAE": round(mae,2), "RMSE": round(rmse,2), "NRMSE": round(nrmse,4),
    "MAPE": round(mape,2), "Accuracy (%)": round(acc,2),
    "R-squared": round(r2,3), "Adjusted R-squared": round(adj_r2,3),
    "BIC": round(bic,2)
})

# SARIMA(0,1,0)(0,0,1,12)
y_true, y_pred = rolling_forecast("SARIMA", order=(0,1,1), seasonal_order=(0,0,1,12))
mae, rmse, nrmse, mape, acc, r2, adj_r2, bic = compute_metrics(y_true, y_pred)
comparison.append({
    "Model": "SARIMA(0,1,1)(0,0,1,12)",
    "MAE": round(mae,2), "RMSE": round(rmse,2), "NRMSE": round(nrmse,4),
    "MAPE": round(mape,2), "Accuracy (%)": round(acc,2),
    "R-squared": round(r2,3), "Adjusted R-squared": round(adj_r2,3),
    "BIC": round(bic,2)
})

# ==== Final DataFrame ====
comparison_df = pd.DataFrame(comparison)

# ==== Print ====
pd.set_option("display.float_format", "{:,.2f}".format)

print(f"\n=== 📊 Full Backtest Comparison ({start_date[:4]}–{end_date.strftime('%Y-%m')}) ===")
print(comparison_df.to_string(index=False))