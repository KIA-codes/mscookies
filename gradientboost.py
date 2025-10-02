import pandas as pd
import numpy as np
import matplotlib.pyplot as plt
from sklearn.ensemble import GradientBoostingRegressor

# --------------------------
# Load and preprocess data
# --------------------------
df = pd.read_csv("MSCOOKIES FULL.csv")

# Clean numeric columns
df['PRICE'] = df['PRICE'].replace('[₱,]', '', regex=True).astype(float)
df['TOTAL_SALE'] = df['QUANTITY'] * df['PRICE']

# Convert DATE to datetime
df['DATE'] = pd.to_datetime(df['DATE'], format='%d-%b-%y', errors='coerce')

# Aggregate sales per month
monthly_sales = df.groupby(pd.Grouper(key='DATE', freq='MS'))['TOTAL_SALE'].sum()
monthly_sales = monthly_sales.asfreq('MS').fillna(0)

# Prepare data for ML
monthly_sales_df = monthly_sales.reset_index()
monthly_sales_df['MonthIndex'] = np.arange(len(monthly_sales_df))  # Numeric index

X = monthly_sales_df[['MonthIndex']]
y = monthly_sales_df['TOTAL_SALE']

# --------------------------
# Train Gradient Boosting Model
# --------------------------
model = GradientBoostingRegressor(n_estimators=200, learning_rate=0.05, max_depth=5, random_state=42)
model.fit(X, y)

# Predict next 3 months
future_indices = np.arange(len(monthly_sales_df), len(monthly_sales_df)+3).reshape(-1,1)
future_dates = pd.date_range(start=monthly_sales.index[-1] + pd.offsets.MonthBegin(),
                             periods=3, freq="MS")
predictions = model.predict(future_indices)

# --------------------------
# Display Results
# --------------------------
results = pd.DataFrame({
    "Predicted Sales": predictions
}, index=future_dates)

print("=== Gradient Boosting Forecast: Next 3 Months ===")
print(results.round(2))

# --------------------------
# Plot
# --------------------------
plt.figure(figsize=(10,5))
plt.plot(monthly_sales_df['DATE'], y, label="Observed Sales", marker='o')
plt.plot(future_dates, predictions, label="Gradient Boosting Forecast", marker='x', color='green')
plt.title("Monthly Sales Forecast using Gradient Boosting")
plt.xlabel("Date")
plt.ylabel("Sales (₱)")
plt.legend()
plt.grid(True)
plt.show()
