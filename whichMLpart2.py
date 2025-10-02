import pandas as pd
import numpy as np
from sklearn.ensemble import RandomForestRegressor, GradientBoostingRegressor
from sklearn.tree import DecisionTreeRegressor
from sklearn.preprocessing import PolynomialFeatures
from sklearn.linear_model import LinearRegression
from sklearn.neighbors import KNeighborsRegressor
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
import warnings
warnings.filterwarnings("ignore")

# === Load Excel ===
file_path = "mscookiesWHOLE.xlsx"
df = pd.read_excel(file_path)

# Ensure proper datetime and sorting
df['DATE'] = pd.to_datetime(df['DATE'], errors='coerce')
df = df.sort_values("DATE")

# Use PRICE as sales and handle NaNs
df['PRICE'] = pd.to_numeric(df['PRICE'], errors='coerce')   # force numeric
df['PRICE'] = df['PRICE'].fillna(method='ffill')            # fill NaN with last valid value
df['PRICE'] = df['PRICE'].fillna(0)                         # if still NaN, replace with 0

sales_series = df['PRICE'].values
# === Metrics helper ===
def compute_metrics(y_true, y_pred, model_name):
    mae = mean_absolute_error(y_true, y_pred)
    rmse = np.sqrt(mean_squared_error(y_true, y_pred))
    nrmse = rmse / np.mean(y_true)
    mape = np.mean(np.abs((y_true - y_pred) / y_true)) * 100
    acc = 100 - mape
    r2 = r2_score(y_true, y_pred)
    return {
        "Model": model_name,
        "MAE": round(mae,2),
        "RMSE": round(rmse,2),
        "NRMSE": round(nrmse,4),
        "MAPE": round(mape,2),
        "Accuracy (%)": round(acc,2),
        "R-squared": round(r2,4)
    }

# === Walk-forward validation (ML only) ===
def walk_forward_eval(model_func, series, n_test):
    train, test = series[:-n_test], series[-n_test:]
    history = list(train)
    predictions = []

    for i in range(len(test)):
        model_obj = model_func(history)

        # Polynomial regression special handling
        if isinstance(model_obj, tuple):
            model, poly = model_obj
            X_new = np.array([[len(history)]])
            yhat = model.predict(poly.transform(X_new))[0]
        else:
            X_new = np.array([[len(history)]])
            yhat = model_obj.predict(X_new)[0]

        predictions.append(yhat)
        history.append(test[i])

    return compute_metrics(test, predictions, model_func.__name__)

# === Model builders (ML only) ===
def random_forest_model(history):
    X = np.arange(len(history)).reshape(-1,1)
    y = history
    model = RandomForestRegressor(n_estimators=200, random_state=42)
    model.fit(X, y)
    return model

def gradient_boosting_model(history):
    X = np.arange(len(history)).reshape(-1,1)
    y = history
    model = GradientBoostingRegressor(random_state=42)
    model.fit(X, y)
    return model

def decision_tree_model(history):
    X = np.arange(len(history)).reshape(-1,1)
    y = history
    model = DecisionTreeRegressor(random_state=42)
    model.fit(X, y)
    return model

def knn_model(history, k=5):
    X = np.arange(len(history)).reshape(-1,1)
    y = history
    model = KNeighborsRegressor(n_neighbors=k)
    model.fit(X, y)
    return model

def poly_reg_model(history, degree=3):
    X = np.arange(len(history)).reshape(-1,1)
    y = history
    poly = PolynomialFeatures(degree=degree)
    X_poly = poly.fit_transform(X)
    model = LinearRegression()
    model.fit(X_poly, y)
    return (model, poly)

# === Run Backtest (last 36 months for testing) ===
n_test = 36
results = []
results.append(walk_forward_eval(random_forest_model, sales_series, n_test))
results.append(walk_forward_eval(gradient_boosting_model, sales_series, n_test))
results.append(walk_forward_eval(decision_tree_model, sales_series, n_test))
results.append(walk_forward_eval(knn_model, sales_series, n_test))
results.append(walk_forward_eval(poly_reg_model, sales_series, n_test))

# === Show Results ===
results_df = pd.DataFrame(results)
print("\n=== Walk-Forward Backtest Results (ML only) ===")
print(results_df.to_string(index=False))
