# %% ARIMA + Linear Regression Hybrid
import pandas as pd
import numpy as np
from sklearn.linear_model import LinearRegression
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
from statsmodels.tsa.arima.model import ARIMA
import warnings
warnings.filterwarnings("ignore")

# ==== Load dataset ====
df = pd.read_excel("mscookiesWHOLE.xlsx", sheet_name="Sheet1")
df["DATE"] = pd.to_datetime(df["DATE"], errors="coerce")
df["SALES"] = df["PRICE"].astype(float)

# ==== Aggregate monthly sales ====
monthly_sales = df.groupby(pd.Grouper(key="DATE", freq="M"))["SALES"].sum()

# ==== Define backtest range ====
start_date = "2021-09-20"
end_date = monthly_sales.index.max()
test_series = monthly_sales.loc[start_date:end_date]

# ==== Backtest ARIMA + Linear Regression ====
results = []
for i in range(1, len(test_series)):
    train = test_series[:i]
    test = test_series[i:i+1]
    try:
        model = ARIMA(train, order=(0,1,1))
        fit = model.fit()
        forecast = fit.forecast(1)[0]
    except:
        forecast = train.mean()

    results.append({
        "DATE": test.index[0],
        "ARIMA_Forecast": forecast,
        "Actual_Sales": test.values[0]
    })

all_months = pd.DataFrame(results)

# ==== Residual Modeling (Linear Regression) ====
all_months["Residuals"] = all_months["Actual_Sales"] - all_months["ARIMA_Forecast"]
X_time = np.arange(len(all_months)).reshape(-1,1)
y_resid = all_months["Residuals"].values

lr_model = LinearRegression()
lr_model.fit(X_time, y_resid)
resid_pred = lr_model.predict(X_time)

# Final Hybrid forecast
all_months["Hybrid_Forecast"] = all_months["ARIMA_Forecast"] + resid_pred

# ==== Reached? Columns ====
all_months["Reached?"] = np.where(
    all_months["Actual_Sales"] >= all_months["Hybrid_Forecast"], "Yes", "No"
)
all_months["Diff"] = all_months["Actual_Sales"] - all_months["Hybrid_Forecast"]

# ==== Metrics Function ====
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

# ==== Metrics Table ====
metrics_df = pd.DataFrame({
    "Metric": ["MAE","RMSE","NRMSE","Avg RMSE","MAPE","Accuracy (%)",
               "Reached Accuracy (%)","BIC","Normalized BIC","R-squared","Adjusted R-squared"],
    "ARIMA + LinearReg": compute_metrics(
        all_months["Actual_Sales"], all_months["Hybrid_Forecast"], all_months["Reached?"]
    )
})

# ==== 12-Month Future Forecast ====
final_arima = ARIMA(test_series, order=(0,1,1)).fit()
future_forecast = final_arima.forecast(12)
future_X = np.arange(len(all_months), len(all_months)+12).reshape(-1,1)
future_hybrid = future_forecast.values + lr_model.predict(future_X)

future_dates = pd.date_range(start=test_series.index[-1] + pd.offsets.MonthBegin(1),
                             periods=12, freq="M")

future_df = pd.DataFrame({
    "DATE": future_dates,
    "Hybrid_Forecast": future_hybrid.round(2)
})

# ==== Output ====
print("\n=== Metrics (ARIMA + Linear Regression) ===")
print(metrics_df.round(2))

print("\n=== Backtest Results (Hybrid only) ===")
print(all_months.round(2).to_string(index=False))

print("\n=== 12-Month Future Forecast (Hybrid only) ===")
print(future_df.to_string(index=False))
