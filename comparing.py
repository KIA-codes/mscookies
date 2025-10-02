# %%
import pandas as pd
import numpy as np
from sklearn.model_selection import train_test_split
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score

# ==== ML Models ====
from sklearn.linear_model import LinearRegression, Ridge, Lasso
from sklearn.preprocessing import PolynomialFeatures
from sklearn.tree import DecisionTreeRegressor
from sklearn.ensemble import RandomForestRegressor, ExtraTreesRegressor, GradientBoostingRegressor
from sklearn.svm import SVR
from sklearn.neighbors import KNeighborsRegressor
# from xgboost import XGBRegressor  # uncomment if installed
# from lightgbm import LGBMRegressor  # uncomment if installed
# from catboost import CatBoostRegressor  # uncomment if installed

# ==== Load dataset ====
df = pd.read_excel("mscookiesWHOLE.xlsx", sheet_name="Sheet1")
df["DATE"] = pd.to_datetime(df["DATE"], errors="coerce")
df["SALES"] = df["PRICE"].astype(float)  # Or QUANTITY * PRICE if you want revenue

# ==== Aggregate monthly sales ====
monthly_sales = df.groupby(pd.Grouper(key="DATE", freq="M"))["SALES"].sum()

# ==== Feature engineering: lags ====
def create_lag_features(series, n_lags=12):
    df_lags = pd.DataFrame({"y": series})
    for lag in range(1, n_lags + 1):
        df_lags[f"lag_{lag}"] = series.shift(lag)
    return df_lags.dropna()

lags_df = create_lag_features(monthly_sales, n_lags=12)

X = lags_df.drop(columns=["y"]).values
y = lags_df["y"].values

print("Original monthly sales length:", len(monthly_sales))
print("Lagged dataset length:", len(lags_df))

# ==== Train-test split (last 20% as test) ====
X_train, X_test, y_train, y_test = train_test_split(
    X, y, test_size=0.2, shuffle=False
)

# ==== Define models ====
models = {
    "Linear Regression": LinearRegression(),
    "Ridge Regression": Ridge(),
    "Lasso Regression": Lasso(),
    "Polynomial Regression": None,  # handled separately
    "Decision Tree": DecisionTreeRegressor(),
    "Random Forest": RandomForestRegressor(n_estimators=100, random_state=42),
    "Extra Trees": ExtraTreesRegressor(n_estimators=100, random_state=42),
    "Gradient Boosting": GradientBoostingRegressor(n_estimators=100, random_state=42),
    "SVR": SVR(),
    "KNN": KNeighborsRegressor(n_neighbors=5),
    # "XGBoost": XGBRegressor(n_estimators=100, random_state=42),
    # "LightGBM": LGBMRegressor(n_estimators=100, random_state=42),
    # "CatBoost": CatBoostRegressor(verbose=0, random_state=42)
}

# ==== Train & Evaluate ====
results = []
for name, model in models.items():
    if name == "Polynomial Regression":
        poly = PolynomialFeatures(degree=2)
        X_train_poly = poly.fit_transform(X_train)
        X_test_poly = poly.transform(X_test)
        lr = LinearRegression()
        lr.fit(X_train_poly, y_train)
        preds = lr.predict(X_test_poly)
    else:
        model.fit(X_train, y_train)
        preds = model.predict(X_test)
    
    mae = mean_absolute_error(y_test, preds)
    rmse = np.sqrt(mean_squared_error(y_test, preds))
    r2 = r2_score(y_test, preds)
    
    results.append({
        "Model": name,
        "MAE": mae,
        "RMSE": rmse,
        "R²": r2
    })

results_df = pd.DataFrame(results).sort_values(by="MAE")

# ==== Show results ====
print("\n=== ML Models Accuracy Comparison ===")
print(results_df.to_string(index=False))
