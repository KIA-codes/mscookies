# %%
import pandas as pd
import numpy as np
from statsmodels.tsa.holtwinters import ExponentialSmoothing
from sklearn.metrics import mean_squared_error, mean_absolute_error, r2_score
from sklearn.neighbors import KNeighborsRegressor
from sklearn.ensemble import RandomForestRegressor, GradientBoostingRegressor
from sklearn.preprocessing import PolynomialFeatures
from sklearn.linear_model import LinearRegression
import warnings
warnings.filterwarnings("ignore")

# === Load Data ===
df = pd.read_excel("mscookiesWHOLE.xlsx")
df["DATE"] = pd.to_datetime(df["DATE"])
monthly_sales = df.groupby(pd.Grouper(key="DATE", freq="M"))["PRICE"].sum()
y = monthly_sales

# === Metrics ===
def evaluate_forecast(y_true, y_pred, model_name):
    mae = mean_absolute_error(y_true, y_pred)
    rmse = np.sqrt(mean_squared_error(y_true, y_pred))
    nrmse = rmse / (y_true.max() - y_true.min())
    mape = np.mean(np.abs((y_true - y_pred) / y_true)) * 100
    accuracy = 100 - mape
    r2 = r2_score(y_true, y_pred)
    adj_r2 = 1 - (1-r2) * (len(y_true)-1) / (len(y_true)-2)

    return {
        "Model": model_name,
        "MAE": round(mae, 2),
        "RMSE": round(rmse, 2),
        "NRMSE": round(nrmse, 4),
        "MAPE": round(mape, 2),
        "Accuracy (%)": round(accuracy, 2),
        "R-squared": round(r2, 3),
        "Adjusted R-squared": round(adj_r2, 3)
    }

# === Backtest Holt-Winters + ML hybrids ===
def backtest_holtwinters_ml(y, ml_model, model_name):
    y_pred, y_true = [], []

    for i in range(24, len(y)):  # warm-up with 2 years of data
        train, test = y[:i], y[i:i+1]

        # Fit Holt-Winters
        hw_model = ExponentialSmoothing(train, trend="add", seasonal="add", seasonal_periods=12).fit()
        hw_forecast = hw_model.forecast(1)[0]

        # Residuals for ML
        residuals = train - hw_model.fittedvalues
        X_train = np.arange(len(residuals)).reshape(-1, 1)
        y_resid = residuals.values

        # Fit ML on residuals
        if len(X_train) > 5:  # need enough data
            ml_model.fit(X_train, y_resid)
            X_test = np.array([[len(residuals) + 1]])
            resid_forecast = ml_model.predict(X_test)[0]
        else:
            resid_forecast = 0

        final_forecast = hw_forecast + resid_forecast
        y_pred.append(final_forecast)
        y_true.append(test[0])

    return evaluate_forecast(np.array(y_true), np.array(y_pred), model_name)

# === Run all models ===
results = []

# Baseline Holt-Winters
results.append(backtest_holtwinters_ml(y, KNeighborsRegressor(n_neighbors=1), "Holt-Winters (Baseline Only)"))

# Holt-Winters + ML
results.append(backtest_holtwinters_ml(y, KNeighborsRegressor(n_neighbors=3), "Holt-Winters + KNN"))
results.append(backtest_holtwinters_ml(y, LinearRegression(), "Holt-Winters + Polynomial Regression"))
results.append(backtest_holtwinters_ml(y, RandomForestRegressor(n_estimators=100, random_state=42), "Holt-Winters + Random Forest"))
results.append(backtest_holtwinters_ml(y, GradientBoostingRegressor(n_estimators=100, random_state=42), "Holt-Winters + Gradient Boosting"))

# === Results Table ===
results_df = pd.DataFrame(results)
print("\n=== 📊 Holt-Winters + ML Hybrid Backtest (2021–{}) ===".format(y.index[-1].strftime("%Y-%m")))
print(results_df.to_string(index=False))
