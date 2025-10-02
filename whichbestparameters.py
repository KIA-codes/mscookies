# %%
import pandas as pd
from pmdarima import auto_arima
import warnings
warnings.filterwarnings("ignore")

# === Load dataset ===
df = pd.read_excel("mscookiesWHOLE.xlsx", sheet_name="Sheet1")
df["DATE"] = pd.to_datetime(df["DATE"], errors="coerce")

# Use SALES = PRICE (or QUANTITY*PRICE if that's revenue)
df["SALES"] = df["PRICE"].astype(float)

# Aggregate to monthly sales
sales_series = df.groupby(pd.Grouper(key="DATE", freq="M"))["SALES"].sum()

# === AUTO ARIMA ===
print("Running Auto ARIMA...")
auto_arima_model = auto_arima(  
    sales_series,
    seasonal=False,         # pure ARIMA
    stepwise=True,
    suppress_warnings=True,
    error_action="ignore"
)
print("\nBest ARIMA order:", auto_arima_model.order)

# === AUTO SARIMA ===
print("\nRunning Auto SARIMA...")
auto_sarima_model = auto_arima(
    sales_series,
    seasonal=True,          # enable seasonality
    m=12,                   # 12 = monthly seasonality
    stepwise=True,
    suppress_warnings=True,
    error_action="ignore"
)
print("\nBest SARIMA order:", auto_sarima_model.order)
print("Best SARIMA seasonal order:", auto_sarima_model.seasonal_order)
