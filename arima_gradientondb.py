# %% ARIMA + Gradient Boosting Hybrid (JSON Output Only with Future Table)
import pandas as pd
import numpy as np
from sklearn.ensemble import GradientBoostingRegressor
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
from statsmodels.tsa.arima.model import ARIMA
import warnings, json, sys
import pymysql
warnings.filterwarnings("ignore")

# ==== Connect to your MySQL database ====
conn = pymysql.connect(
    host="localhost",
    user="root",
    password="",
    database="mscookies"
)

# ==== Query sales data ====
query = """
    SELECT sales_date AS DATE, subtotal AS SALES
    FROM sales
"""
df = pd.read_sql(query, conn)

# ==== Convert and clean ====
df["DATE"] = pd.to_datetime(df["DATE"], errors="coerce")
df["SALES"] = pd.to_numeric(df["SALES"], errors="coerce")

# ==== Aggregate monthly sales ====
monthly_sales = df.groupby(pd.Grouper(key="DATE", freq="M"))["SALES"].sum()

# ==== Define backtest range ====
start_date = "2021-09-20"
end_date = monthly_sales.index.max()
test_series = monthly_sales.loc[start_date:end_date]

# ==== Backtest SARIMA + Gradient Boosting ====
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
        "DATE": str(test.index[0].date()),
        "ARIMA_Forecast": float(forecast),
        "Actual_Sales": float(test.values[0])
    })

all_months = pd.DataFrame(results)

# ==== Residual Modeling (Gradient Boosting) ====
all_months["Residuals"] = all_months["Actual_Sales"] - all_months["ARIMA_Forecast"]
X_time = np.arange(len(all_months)).reshape(-1,1)
y_resid = all_months["Residuals"].values

gb_model = GradientBoostingRegressor(
    n_estimators=200, learning_rate=0.05, max_depth=3, random_state=42
)
gb_model.fit(X_time, y_resid)
resid_pred = gb_model.predict(X_time)

# Final Hybrid forecast
all_months["Hybrid_Forecast"] = all_months["ARIMA_Forecast"] + resid_pred

# ==== Metrics Function ====
def compute_metrics(y_true, y_pred):
    y_true = np.array(y_true)
    y_pred = np.array(y_pred)
    mask = y_true > 0
    mape = np.mean(np.abs((y_true[mask] - y_pred[mask]) / y_true[mask])) * 100 if mask.sum() > 0 else None
    accuracy = 100 - mape if mape is not None else None
    mae = mean_absolute_error(y_true, y_pred)
    rmse = np.sqrt(mean_squared_error(y_true, y_pred))
    r2 = r2_score(y_true, y_pred)
    return {
        "MAE": float(mae),
        "RMSE": float(rmse),
        "MAPE": None if mape is None else float(mape),
        "Accuracy": None if accuracy is None else float(accuracy),
        "R2": float(r2)
    }

metrics = compute_metrics(all_months["Actual_Sales"], all_months["Hybrid_Forecast"])

# ==== 12-Month Future Forecast (Hybrid only) ====
final_arima = ARIMA(test_series, order=(0,1,1)).fit()
future_forecast = final_arima.forecast(12)
future_X = np.arange(len(all_months), len(all_months)+12).reshape(-1,1)
future_hybrid = future_forecast.values + gb_model.predict(future_X)

future_dates = pd.date_range(start=test_series.index[-1] + pd.offsets.MonthBegin(1),
                             periods=12, freq="M")

future_df = pd.DataFrame({
    "DATE": future_dates.strftime("%Y-%m"),
    "Future_Forecast": future_hybrid.round(2)
})

# ==== Combine Backtest + Future for chart output ====
combined_df = pd.concat([
    all_months[["DATE","Actual_Sales","Hybrid_Forecast"]],
    future_df[["DATE","Future_Forecast"]]
], ignore_index=True)

# ==== Replace NaN/inf with None for JSON safety ====
combined_df = combined_df.replace([np.nan, np.inf, -np.inf], None)
future_df = future_df.replace([np.nan, np.inf, -np.inf], None)

# ==== Final JSON Output ====
output = {
    "forecast": combined_df.to_dict(orient="records"),
    "metrics": metrics,
    "future_table": future_df.to_dict(orient="records")
}

json.dump(output, sys.stdout, indent=2, allow_nan=False)
