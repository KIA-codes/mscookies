# %%
import pandas as pd
import numpy as np
from sklearn.metrics import mean_absolute_error, mean_squared_error
from sklearn.ensemble import RandomForestRegressor, GradientBoostingRegressor
from statsmodels.tsa.holtwinters import ExponentialSmoothing
import warnings
warnings.filterwarnings("ignore")

# ==== Load dataset ====
df = pd.read_excel("mscookiesWHOLE.xlsx", sheet_name="Sheet1")
df["DATE"] = pd.to_datetime(df["DATE"], errors="coerce")
df["SALES"] = df["PRICE"].astype(float)  # Or QUANTITY * PRICE if you want revenue

# ==== Aggregate monthly sales ====
monthly_sales = df.groupby(pd.Grouper(key="DATE", freq="M"))["SALES"].sum()

# ==== Backtesting ====
results = []
lags = 6  # number of lags for ML
X, y = [], []

for i in range(len(monthly_sales)):
    train = monthly_sales.iloc[:i]
    actual_value = monthly_sales.iloc[i]

    if i < 12:
        forecast_hw = train.mean() if not train.empty else actual_value

        # Dummy fit to avoid errors
        class DummyFit:
            def __init__(self, values):
                self.fittedvalues = pd.Series(values, index=train.index)
        fit = DummyFit([forecast_hw] * len(train))

    else:
        try:
            model = ExponentialSmoothing(train, seasonal="add", seasonal_periods=12)
            fit = model.fit()
            forecast_hw = fit.forecast(1)[0]
        except:
            forecast_hw = train.mean()
            class DummyFit:
                def __init__(self, values):
                    self.fittedvalues = pd.Series(values, index=train.index)
            fit = DummyFit([forecast_hw] * len(train))

    # Residual correction with ML (RandomForest + GradientBoosting)
    residuals = train - fit.fittedvalues
    if len(residuals) > lags:
        lagged = []
        target = []
        for j in range(lags, len(residuals)):
            lagged.append(residuals.values[j-lags:j])
            target.append(residuals.values[j])
        X_train, y_train = np.array(lagged), np.array(target)

        rf = RandomForestRegressor(n_estimators=200, random_state=42).fit(X_train, y_train)
        gb = GradientBoostingRegressor(n_estimators=200, random_state=42).fit(X_train, y_train)

        # Prepare test lag
        test_lag = residuals.values[-lags:].reshape(1, -1)
        corr_rf = rf.predict(test_lag)[0]
        corr_gb = gb.predict(test_lag)[0]
        forecast_hybrid = forecast_hw + (corr_rf + corr_gb) / 2
    else:
        forecast_hybrid = forecast_hw

    results.append({
        "DATE": monthly_sales.index[i],
        "Actual": actual_value,
        "HoltWinters": forecast_hw,
        "Hybrid": forecast_hybrid
    })

backtest_df = pd.DataFrame(results)

# ==== Metrics ====
def metrics(y_true, y_pred):
    mae = mean_absolute_error(y_true, y_pred)
    rmse = np.sqrt(mean_squared_error(y_true, y_pred))
    mape = np.mean(np.abs((y_true - y_pred) / (y_true + 1e-8))) * 100  # safe div
    accuracy = 100 - mape
    return mae, rmse, mape, accuracy

y_true = backtest_df["Actual"].values
mae_hw, rmse_hw, mape_hw, acc_hw = metrics(y_true, backtest_df["HoltWinters"].values)
mae_hy, rmse_hy, mape_hy, acc_hy = metrics(y_true, backtest_df["Hybrid"].values)

print("=== Backtest Accuracy ===")
print(f"Holt-Winters -> MAE: {mae_hw:.2f}, RMSE: {rmse_hw:.2f}, MAPE: {mape_hw:.2f}%, Accuracy: {acc_hw:.2f}%")
print(f"Hybrid HW+RF+GB -> MAE: {mae_hy:.2f}, RMSE: {rmse_hy:.2f}, MAPE: {mape_hy:.2f}%, Accuracy: {acc_hy:.2f}%")

# ==== 12-Month Ahead Forecast ====
final_model = ExponentialSmoothing(monthly_sales, seasonal="add", seasonal_periods=12).fit()
future_hw = final_model.forecast(12)

# Residual correction for forecast horizon
residuals_full = monthly_sales - final_model.fittedvalues
lagged = []
target = []
for j in range(lags, len(residuals_full)):
    lagged.append(residuals_full.values[j-lags:j])
    target.append(residuals_full.values[j])

X_train, y_train = np.array(lagged), np.array(target)
rf = RandomForestRegressor(n_estimators=200, random_state=42).fit(X_train, y_train)
gb = GradientBoostingRegressor(n_estimators=200, random_state=42).fit(X_train, y_train)

hybrid_forecast = []
last_resid = residuals_full.values[-lags:].tolist()
for f in range(12):
    test_lag = np.array(last_resid[-lags:]).reshape(1, -1)
    corr_rf = rf.predict(test_lag)[0]
    corr_gb = gb.predict(test_lag)[0]
    correction = (corr_rf + corr_gb) / 2
    hybrid_forecast.append(future_hw[f] + correction)
    last_resid.append(correction)

future_dates = pd.date_range(start=monthly_sales.index[-1] + pd.offsets.MonthBegin(1), periods=12, freq="M")
forecast_df = pd.DataFrame({"HoltWinters": future_hw.values, "Hybrid": hybrid_forecast}, index=future_dates)

print("\n=== 12-Month Ahead Forecast ===")
print(forecast_df)
