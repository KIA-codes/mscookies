# %%
import pandas as pd
import numpy as np
from sklearn.ensemble import RandomForestRegressor
from sklearn.metrics import mean_absolute_error, mean_squared_error
from statsmodels.tsa.holtwinters import ExponentialSmoothing
import warnings
warnings.filterwarnings("ignore")

# ==== Load dataset ====
df = pd.read_excel("mscookiesWHOLE.xlsx", sheet_name="Sheet1")
df["DATE"] = pd.to_datetime(df["DATE"], errors="coerce")
df["SALES"] = df["PRICE"].astype(float)  # Or QUANTITY*PRICE for revenue

# ==== Aggregate monthly sales ====
monthly_sales = df.groupby(pd.Grouper(key="DATE", freq="M"))["SALES"].sum()

# ==== Train/Test Split (last 12 months as test) ====
train = monthly_sales.iloc[:-12]
test = monthly_sales.iloc[-12:]

# ==== Holt-Winters Forecast ====
hw_model = ExponentialSmoothing(train, seasonal="add", seasonal_periods=12)
hw_fit = hw_model.fit()
hw_forecast = hw_fit.forecast(12)

# ==== Compute Residuals for ML Training ====
residuals = train - hw_fit.fittedvalues

# ==== Prepare Features for Random Forest ====
# Use lagged sales and HW fitted values as features
lags = 12
X_train, y_train = [], []
for i in range(lags, len(residuals)):
    X_train.append(np.concatenate([train.values[i-lags:i], hw_fit.fittedvalues[i-lags:i]]))
    y_train.append(residuals.values[i])
X_train, y_train = np.array(X_train), np.array(y_train)

# Train Random Forest on residuals
rf_model = RandomForestRegressor(n_estimators=200, random_state=42)
rf_model.fit(X_train, y_train)

# ==== Predict Residuals for Test Period ====
# Build features for test months
# ==== Corrected Test Features for Random Forest ====
X_test = []
history = list(train.values)  # start with training data

for i in range(12):  # for 12 test months
    # Take last 'lags' sales for features
    lag_sales = history[-lags:]
    
    # Take last 'lags' HW fitted values for features
    # For test period, use HW forecast as "fitted" proxy
    if i < len(hw_forecast):
        lag_hw = list(hw_forecast.values[max(0, i-lags):i])
        # Pad if lag_hw shorter than lags
        lag_hw = [0]*(lags-len(lag_hw)) + lag_hw
    else:
        lag_hw = [0]*lags
    
    X_test.append(np.concatenate([lag_sales, lag_hw]))
    
    # Append the next actual + HW residual to history after prediction
    # This is optional if doing recursive prediction; here we just update history with forecast
    history.append(hw_forecast.values[i])

X_test = np.array(X_test)


rf_pred_residuals = rf_model.predict(X_test)

# ==== Hybrid Forecast ====
hybrid_forecast = hw_forecast.values + rf_pred_residuals

# ==== Evaluation Function ====
def evaluate_forecast(y_true, y_pred):
    mask = y_true != 0  # ignore zero sales
    y_true, y_pred = y_true[mask], y_pred[mask]
    mae = mean_absolute_error(y_true, y_pred)
    rmse = np.sqrt(mean_squared_error(y_true, y_pred))
    mape = np.mean(np.abs((y_true - y_pred)/y_true)) * 100
    accuracy = 100 - mape
    return {"MAE": mae, "RMSE": rmse, "MAPE (%)": mape, "Accuracy (%)": accuracy}

# ==== Evaluate Hybrid Forecast ====
metrics = evaluate_forecast(test.values, hybrid_forecast)
print("=== Hybrid Holt-Winters + Random Forest ===")
print(metrics)

# ==== Backtest: Did Past Data Reach Forecast? ====
backtest_df = pd.DataFrame({
    "DATE": test.index,
    "Forecasted_Sales": hybrid_forecast,
    "Actual_Sales": test.values
})
backtest_df["Reached?"] = np.where(backtest_df["Actual_Sales"] >= backtest_df["Forecasted_Sales"], "Yes", "No")
print("\n=== Backtest Results (Reached per Month) ===")
print(backtest_df)
