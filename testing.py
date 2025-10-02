# %%
import pandas as pd
import numpy as np
from sklearn.metrics import mean_absolute_error, mean_squared_error
from sklearn.ensemble import RandomForestRegressor, GradientBoostingRegressor
from sklearn.svm import SVR
from sklearn.linear_model import LinearRegression
from sklearn.neural_network import MLPRegressor
import warnings
warnings.filterwarnings("ignore")

# ==== Load dataset ====
df = pd.read_excel("mscookiesWHOLE.xlsx", sheet_name="Sheet1")
df["DATE"] = pd.to_datetime(df["DATE"], errors="coerce")
df["SALES"] = df["PRICE"].astype(float)  # Or QUANTITY * PRICE if you want revenue

# ==== Aggregate monthly sales ====
monthly_sales = df.groupby(pd.Grouper(key="DATE", freq="M"))["SALES"].sum()

# Train/Test split (last 12 months as test)
train = monthly_sales.iloc[:-12]
test = monthly_sales.iloc[-12:]

# ==== Holt-Winters (ETS) Model ====
from statsmodels.tsa.holtwinters import ExponentialSmoothing
ets_model = ExponentialSmoothing(train, seasonal="add", seasonal_periods=12)
ets_fit = ets_model.fit()

# Forecast using Holt-Winters
hw_forecast = ets_fit.forecast(len(test))

# Compute residuals for ML models
residuals_train = train - ets_fit.fittedvalues

# Prepare features for ML: use lagged residuals
def create_lag_features(series, lags=3):
    df_lag = pd.DataFrame()
    for lag in range(1, lags + 1):
        df_lag[f"lag_{lag}"] = series.shift(lag)
    return df_lag

lags = 3
X_train = create_lag_features(residuals_train, lags=lags).iloc[lags:]
y_train = residuals_train.iloc[lags:]

# Use last observed lags to forecast residuals for test
last_residuals = residuals_train.iloc[-lags:].values.reshape(1, -1)

# ML models to test
models = {
    "RandomForest": RandomForestRegressor(n_estimators=100, random_state=42),
    "GradientBoosting": GradientBoostingRegressor(n_estimators=100, random_state=42),
    "SVR": SVR(),
    "LinearRegression": LinearRegression(),
    "MLPRegressor": MLPRegressor(hidden_layer_sizes=(50,50), max_iter=1000, random_state=42)
}

results = {}

for name, model in models.items():
    # Train ML on residuals
    model.fit(X_train, y_train)
    
    # Forecast residuals step by step
    pred_residuals = []
    current_lags = last_residuals.copy()
    for _ in range(len(test)):
        res_pred = model.predict(current_lags)[0]
        pred_residuals.append(res_pred)
        # Update lags
        current_lags = np.roll(current_lags, -1)
        current_lags[0, -1] = res_pred
    
    # Hybrid forecast = Holt-Winters + predicted residuals
    hybrid_forecast = hw_forecast.values + np.array(pred_residuals)
    
    # Evaluation
    mask = test.values != 0
    y_true = test.values[mask]
    y_pred = hybrid_forecast[mask]
    
    mae = mean_absolute_error(y_true, y_pred)
    rmse = np.sqrt(mean_squared_error(y_true, y_pred))
    mape = np.mean(np.abs((y_true - y_pred) / y_true)) * 100
    accuracy = 100 - mape
    
    results[name] = {
        "MAE": mae,
        "RMSE": rmse,
        "MAPE (%)": mape,
        "Accuracy (%)": accuracy
    }

# ==== Results comparison ====
results_df = pd.DataFrame(results).T
print("=== Hybrid Holt-Winters + ML Models Accuracy ===")
print(results_df)
