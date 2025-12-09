from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
import pandas as pd
import joblib
import os
import pymysql
from datetime import datetime, timedelta
from urllib.parse import unquote_plus

from statsforecast import StatsForecast
from statsforecast.models import AutoETS
from statsmodels.tsa.holtwinters import ExponentialSmoothing
import numpy as np

app = FastAPI(title="Drug Demand Forecast API – AutoETS")

# Enable frontend access
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

# Exception handler for unhandled errors
@app.exception_handler(Exception)
async def global_exception_handler(request, exc):
    print(f"Unhandled exception: {exc}")
    import traceback
    traceback.print_exc()
    return JSONResponse(
        status_code=500,
        content={
            "error": f"Internal server error: {str(exc)}",
            "detail": "An unexpected error occurred. Please check server logs."
        }
    )

# Base folder and models
base = "Drug_Demand_Prediction"

# Database connection details
DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '',
    'database': 'system',
    'charset': 'utf8mb4'
}

def get_db_connection():
    """Create database connection"""
    try:
        return pymysql.connect(**DB_CONFIG)
    except Exception as e:
        print(f"Database connection error: {e}")
        return None

def normalize_drug_name(drug_name):
    """Normalize drug name: decode URL encoding, trim, handle spaces"""
    if not drug_name:
        return ""
    # Decode URL encoding (e.g., + to space, %20 to space)
    normalized = unquote_plus(drug_name)
    # Trim whitespace
    normalized = normalized.strip()
    # Replace multiple spaces with single space
    normalized = ' '.join(normalized.split())
    return normalized

def get_drug_info_from_db(drug_name):
    """Get drug information from drugs table"""
    conn = get_db_connection()
    if not conn:
        return None
    
    try:
        cursor = conn.cursor(pymysql.cursors.DictCursor)
        
        # Try exact match first
        query = """
            SELECT drug_name, current_stock, department, expiry_date
            FROM drugs
            WHERE LOWER(TRIM(drug_name)) = LOWER(TRIM(%s))
            LIMIT 1
        """
        cursor.execute(query, (drug_name,))
        row = cursor.fetchone()
        
        if not row:
            # Try fuzzy match (contains)
            query = """
                SELECT drug_name, current_stock, department, expiry_date
                FROM drugs
                WHERE LOWER(drug_name) LIKE LOWER(%s)
                LIMIT 1
            """
            cursor.execute(query, (f"%{drug_name}%",))
            row = cursor.fetchone()
        
        cursor.close()
        return row
    except Exception as e:
        print(f"Error fetching drug info from database: {e}")
        return None
    finally:
        if conn:
            conn.close()

def get_monthly_demand_from_db(drug_name=None):
    """Get monthly demand data from database (drug_checkouts table)"""
    conn = get_db_connection()
    if not conn:
        return pd.DataFrame()
    
    try:
        cursor = conn.cursor(pymysql.cursors.DictCursor)
        
        if drug_name:
            # Normalize drug name first
            normalized_name = normalize_drug_name(drug_name)
            
            # Check table structure first to determine which approach to use
            try:
                cursor.execute("DESCRIBE drug_checkouts")
                columns_info = cursor.fetchall()
                # Handle both dict and tuple formats
                column_names = []
                if columns_info and len(columns_info) > 0:
                    for col in columns_info:
                        if isinstance(col, dict):
                            # DictCursor returns dict with 'Field' key
                            col_name = col.get('Field') or col.get('field')
                            if col_name:
                                column_names.append(col_name)
                        else:
                            # Regular cursor returns tuple, first element is field name
                            if len(col) > 0:
                                column_names.append(col[0])
            except Exception as desc_error:
                print(f"Error describing table structure: {desc_error}")
                # Default to drug_id approach if we can't check structure
                column_names = ['drug_id', 'quantity_dispensed']
            
            has_drug_name = 'drug_name' in column_names
            has_drug_id = 'drug_id' in column_names
            has_quantity = 'quantity' in column_names
            has_quantity_dispensed = 'quantity_dispensed' in column_names
            
            rows = []
            
            # Try drug_id join approach first (most common in this database)
            if has_drug_id and has_quantity_dispensed:
                try:
                    # Exact match
                    query = """
                        SELECT 
                            CONCAT(YEAR(dc.checkout_time), '-', LPAD(MONTH(dc.checkout_time), 2, '0'), '-01') as month,
                            d.drug_name,
                            SUM(dc.quantity_dispensed) as quantity
                        FROM drug_checkouts dc
                        INNER JOIN drugs d ON dc.drug_id = d.drug_id
                        WHERE dc.checkout_time >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                            AND LOWER(TRIM(d.drug_name)) = LOWER(TRIM(%s))
                        GROUP BY YEAR(dc.checkout_time), MONTH(dc.checkout_time), d.drug_name
                    """
                    cursor.execute(query, (normalized_name,))
                    rows = cursor.fetchall()
                    print(f"Query executed for '{normalized_name}': Found {len(rows)} rows with exact match")
                    
                    # If no exact match, try fuzzy match (contains)
                    if not rows:
                        query = """
                            SELECT 
                                CONCAT(YEAR(dc.checkout_time), '-', LPAD(MONTH(dc.checkout_time), 2, '0'), '-01') as month,
                                d.drug_name,
                                SUM(dc.quantity_dispensed) as quantity
                            FROM drug_checkouts dc
                            INNER JOIN drugs d ON dc.drug_id = d.drug_id
                            WHERE dc.checkout_time >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                                AND LOWER(d.drug_name) LIKE LOWER(%s)
                            GROUP BY YEAR(dc.checkout_time), MONTH(dc.checkout_time), d.drug_name
                        """
                        cursor.execute(query, (f"%{normalized_name}%",))
                        rows = cursor.fetchall()
                        print(f"Query executed for '{normalized_name}': Found {len(rows)} rows with fuzzy match")
                except Exception as e1:
                    print(f"Error with drug_id join query for '{normalized_name}': {e1}")
                    import traceback
                    traceback.print_exc()
                    rows = []
            
            # If no results and drug_name column exists, try that approach
            if not rows and has_drug_name and has_quantity:
                try:
                    # Exact match
                    query = """
                        SELECT 
                            CONCAT(YEAR(checkout_time), '-', LPAD(MONTH(checkout_time), 2, '0'), '-01') as month,
                            drug_name,
                            SUM(quantity) as quantity
                        FROM drug_checkouts
                        WHERE checkout_time >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                            AND LOWER(TRIM(drug_name)) = LOWER(TRIM(%s))
                        GROUP BY YEAR(checkout_time), MONTH(checkout_time), drug_name
                    """
                    cursor.execute(query, (normalized_name,))
                    rows = cursor.fetchall()
                    
                    # If no exact match, try fuzzy match (contains)
                    if not rows:
                        query = """
                            SELECT 
                                CONCAT(YEAR(checkout_time), '-', LPAD(MONTH(checkout_time), 2, '0'), '-01') as month,
                                drug_name,
                                SUM(quantity) as quantity
                            FROM drug_checkouts
                            WHERE checkout_time >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                                AND LOWER(drug_name) LIKE LOWER(%s)
                            GROUP BY YEAR(checkout_time), MONTH(checkout_time), drug_name
                        """
                        cursor.execute(query, (f"%{normalized_name}%",))
                        rows = cursor.fetchall()
                except Exception as e2:
                    print(f"Error with drug_name query: {e2}")
                    rows = []
        else:
            rows = []
            try:
                # Try with drug_name first
                query = """
                    SELECT 
                        CONCAT(YEAR(checkout_time), '-', LPAD(MONTH(checkout_time), 2, '0'), '-01') as month,
                        drug_name,
                        SUM(quantity) as quantity
                    FROM drug_checkouts
                    WHERE checkout_time >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                    GROUP BY YEAR(checkout_time), MONTH(checkout_time), drug_name
                """
                cursor.execute(query)
                rows = cursor.fetchall()
            except Exception as e1:
                # If drug_name doesn't exist, use drug_id join
                try:
                    query = """
                        SELECT 
                            CONCAT(YEAR(dc.checkout_time), '-', LPAD(MONTH(dc.checkout_time), 2, '0'), '-01') as month,
                            d.drug_name,
                            SUM(dc.quantity_dispensed) as quantity
                        FROM drug_checkouts dc
                        INNER JOIN drugs d ON dc.drug_id = d.drug_id
                        WHERE dc.checkout_time >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                        GROUP BY YEAR(dc.checkout_time), MONTH(dc.checkout_time), d.drug_name
                    """
                    cursor.execute(query)
                    rows = cursor.fetchall()
                except Exception as e2:
                    print(f"Error with drug_name query for all drugs: {e1}")
                    print(f"Error with drug_id join query for all drugs: {e2}")
                    rows = []
        
        cursor.close()
        
        if rows:
            df = pd.DataFrame(rows)
            print(f"Created DataFrame with {len(df)} rows, columns: {df.columns.tolist()}")
            
            # The query already filtered by drug_name, so all rows should be for the requested drug
            # No need to filter again - this was causing rows to be dropped due to name mismatch
            if drug_name and 'drug_name' in df.columns:
                print(f"DataFrame contains drug names: {df['drug_name'].unique().tolist()}")
            
            # Parse month column - it should be in format 'YYYY-MM-01' from CONCAT(YEAR, MONTH)
            # Convert month column to string first to handle any type issues
            if len(df) > 0 and 'month' in df.columns:
                df['month'] = df['month'].astype(str).str.strip()
                
                # Try to parse with explicit format first (YYYY-MM-DD or YYYY-MM-01)
                try:
                    # First try with format YYYY-MM-DD
                    df['month'] = pd.to_datetime(df['month'], format='%Y-%m-%d', errors='coerce')
                except Exception as e:
                    print(f"Error parsing with format %Y-%m-%d: {e}")
                    try:
                        # Try default parsing
                        df['month'] = pd.to_datetime(df['month'], errors='coerce')
                    except Exception as e2:
                        print(f"Error parsing with default format: {e2}")
                        # If all else fails, try to parse as string and extract date part
                        df['month'] = pd.to_datetime(df['month'].str[:10], errors='coerce')
                
                # Drop rows where month parsing failed
                df = df.dropna(subset=['month'])
                
                if len(df) > 0:
                    if 'drug_name' in df.columns:
                        df = df.sort_values(['drug_name', 'month'])
                    else:
                        df = df.sort_values('month')
                    print(f"Final DataFrame: {len(df)} rows after date parsing")
                else:
                    print("Warning: All rows were dropped due to date parsing errors")
            elif len(df) == 0:
                print(f"Warning: DataFrame is empty after filtering for '{normalized_name}'")
            
            return df
        else:
            return pd.DataFrame()
            
    except Exception as e:
        print(f"Error fetching data from database: {e}")
        import traceback
        traceback.print_exc()
        return pd.DataFrame()
    finally:
        if conn:
            conn.close()

def load_static_data():
    """Load static CSV data as fallback"""
    csv_path = f"{base}/monthly_demand.csv"
    if os.path.exists(csv_path):
        try:
            return pd.read_csv(csv_path, parse_dates=['month'])
        except:
            return pd.DataFrame()
    return pd.DataFrame()

# Try to load from database first, fallback to CSV
data = get_monthly_demand_from_db()
if data.empty:
    data = load_static_data()

# -----------------------------
# HOME ROUTE
# -----------------------------
@app.get("/")
def home():
    return {
        "message": "Welcome to the Drug Demand Forecast API (AutoETS)",
        "routes": ["/predict", "/predict/{drug_name}"]
    }

# -----------------------------
# LIST AVAILABLE DRUGS
# -----------------------------
@app.get("/predict")
def list_drugs():
    # Get drugs from database
    db_drugs = get_monthly_demand_from_db()
    if not db_drugs.empty:
        drugs = sorted(db_drugs['drug_name'].unique().tolist())
    else:
        # Fallback to static data
        drugs = sorted(data['drug_name'].unique().tolist()) if not data.empty else []
    return {"available_drugs": drugs}

def find_model_file(drug_name):
    """Find model file with various naming conventions"""
    models_dir = f"{base}/models"
    if not os.path.exists(models_dir):
        return None
    
    # Try different naming formats
    formats = [
        drug_name.replace(' ', '_'),  # "Paracetamol 500 mg Tablets" -> "Paracetamol_500_mg_Tablets"
        drug_name.replace('/', '_').replace(' ', '_'),  # Handle slashes
        drug_name.replace('-', '_').replace(' ', '_'),  # Handle hyphens
        drug_name.replace(' ', '_').replace('/', '_').replace('-', '_'),  # Combined
    ]
    
    for fmt in formats:
        model_path = f"{models_dir}/autoets_{fmt}.pkl"
        if os.path.exists(model_path):
            return model_path
    
    # Try finding by case-insensitive match
    for filename in os.listdir(models_dir):
        if filename.startswith('autoets_') and filename.endswith('.pkl'):
            # Extract drug name from filename
            model_drug = filename.replace('autoets_', '').replace('.pkl', '').replace('_', ' ')
            # Compare case-insensitively
            if model_drug.lower() == drug_name.lower():
                return f"{models_dir}/{filename}"
            # Also try with underscores
            if model_drug.replace('_', ' ').lower() == drug_name.lower():
                return f"{models_dir}/{filename}"
    
    return None

def ensure_positive_predictions(predictions, historical_mean=None, last_value=None):
    """Ensure all predictions are positive and realistic"""
    # Convert to float array to handle decimal.Decimal types from database
    predictions = np.array(predictions, dtype=float)
    
    # Replace zeros or negatives with reasonable defaults
    if historical_mean is not None and historical_mean > 0:
        default_value = max(historical_mean * 0.5, 10)  # At least 50% of mean or 10, whichever is higher
    elif last_value is not None and last_value > 0:
        default_value = max(last_value * 0.5, 10)
    else:
        default_value = 50  # Minimum realistic demand
    
    # Replace zeros/negatives
    predictions = np.where(predictions <= 0, default_value, predictions)
    
    # Ensure minimum value (at least 10% of mean if available, or 10)
    min_value = max(historical_mean * 0.1 if historical_mean else 10, 10)
    predictions = np.where(predictions < min_value, min_value, predictions)
    
    return predictions.tolist()

# -----------------------------
# AUTOETS PREDICTION ROUTE
# -----------------------------
@app.get("/predict/{drug}")
def predict_autoets(drug: str):
    try:
        # Initialize variables
        using_synthetic = False
        method = None
        
        # Normalize drug name (decode URL encoding, trim spaces)
        try:
            normalized_drug = normalize_drug_name(drug)
        except Exception as e:
            return {
                "error": f"Error normalizing drug name: {str(e)}",
                "drug": drug
            }
        
        # Get checkout history from database first
        try:
            ddf = get_monthly_demand_from_db(normalized_drug)
            print(f"get_monthly_demand_from_db returned DataFrame with {len(ddf)} rows, empty={ddf.empty}")
            if not ddf.empty:
                print(f"DataFrame columns: {ddf.columns.tolist()}")
                print(f"Sample data:\n{ddf.head()}")
        except Exception as e:
            print(f"Error fetching monthly demand from database: {e}")
            import traceback
            traceback.print_exc()
            ddf = pd.DataFrame()
        
        # If no database checkout data, try static CSV
        if ddf.empty and not data.empty:
            # Try normalized name
            ddf = data[data["drug_name"].str.lower() == normalized_drug.lower()].sort_values("month")
            # If still no match, try original drug name
            if ddf.empty:
                ddf = data[data["drug_name"].str.lower() == drug.lower()].sort_values("month")

        # If still no checkout history, check if drug exists in drugs table
        # and use current stock info to generate a reasonable prediction
        if ddf.empty:
            drug_info = get_drug_info_from_db(normalized_drug)
            
            if drug_info:
                using_synthetic = True
                # Drug exists but has no checkout history
                # Generate prediction based on current stock and typical demand patterns
                current_stock = drug_info.get('current_stock', 0) or 0
                
                # If current stock is 0, assume typical monthly demand
                if current_stock == 0:
                    # Estimate based on typical hospital drug usage
                    # Most drugs have monthly demand between 50-500 units
                    estimated_monthly_demand = 100  # Conservative default
                else:
                    # Estimate monthly demand as 20-40% of current stock
                    # This assumes stock covers 2-5 months of demand
                    estimated_monthly_demand = max(current_stock * 0.25, 20)
                
                # Create synthetic historical data for the last 6 months with natural variation
                # Add realistic variation to simulate real-world patterns
                import random
                last_6_months = pd.date_range(end=pd.Timestamp.now(), periods=6, freq='ME')
                
                # Create varied synthetic data with some trend and seasonal-like patterns
                base_demand = estimated_monthly_demand
                quantities = []
                for i in range(6):
                    # Add trend (slight increase over time)
                    trend_factor = 1 + (i - 2.5) * 0.03  # 3% trend per month
                    # Add small random variation (5-10% noise)
                    noise = 1 + random.uniform(-0.08, 0.08)
                    # Add slight cyclical pattern (simulate some seasonality)
                    cycle = 1 + 0.05 * np.sin(i * np.pi / 3)
                    quantity = base_demand * trend_factor * noise * cycle
                    quantities.append(max(quantity, base_demand * 0.5))  # Minimum 50% of base
                
                synthetic_data = pd.DataFrame({
                    'month': last_6_months,
                    'drug_name': normalized_drug,
                    'quantity': quantities
                })
                
                ddf = synthetic_data
                print(f"Using synthetic data for {normalized_drug} based on current stock: {current_stock}")
            else:
                # Drug doesn't exist in database at all
                # Try to find similar drug names
                conn = get_db_connection()
                similar_drugs = []
                if conn:
                    try:
                        cursor = conn.cursor(pymysql.cursors.DictCursor)
                        # Extract main drug name (before first space or number)
                        main_name = normalized_drug.split()[0] if normalized_drug else ""
                        if main_name:
                            query = """
                                SELECT DISTINCT drug_name
                                FROM drugs
                                WHERE LOWER(drug_name) LIKE LOWER(%s)
                                LIMIT 5
                            """
                            cursor.execute(query, (f"%{main_name}%",))
                            similar_drugs = [row['drug_name'] for row in cursor.fetchall()]
                        cursor.close()
                    except:
                        pass
                    finally:
                        conn.close()
                
                error_msg = f"No historical data found for drug: {normalized_drug}"
                suggestion = "Ensure the drug name matches exactly and has checkout history."
                
                if similar_drugs:
                    suggestion += f" Did you mean: {', '.join(similar_drugs[:3])}?"
                else:
                    suggestion += " The drug may need to be added to the database or may have no checkout history yet."
                
                return {
                    "error": error_msg,
                    "drug": normalized_drug,
                    "suggestion": suggestion,
                    "similar_drugs": similar_drugs[:5] if similar_drugs else []
                }
        
        # Calculate historical statistics for fallback
        # Convert to float array to handle decimal.Decimal from MySQL
        values = pd.to_numeric(ddf['quantity'], errors='coerce').values if len(ddf) > 0 else np.array([])
        values = values[~np.isnan(values)]  # Remove any NaN values
        historical_mean = float(np.mean(values)) if len(values) > 0 else None
        last_value = float(values[-1]) if len(values) > 0 else None
        historical_std = float(np.std(values)) if len(values) > 0 else None
        
        # Ensure minimum data points exist
        if len(ddf) < 2:
            # Very minimal data - use simple projection
            if last_value and last_value > 0:
                forecast = [max(last_value * 0.9, 10)] * 3  # Slight decrease, minimum 10
            elif historical_mean and historical_mean > 0:
                forecast = [max(historical_mean * 0.8, 10)] * 3
            else:
                forecast = [50, 50, 50]  # Default reasonable prediction

            last_date = pd.Timestamp.now()
            future_months = pd.date_range(last_date, periods=4, freq="ME")[1:]

            return {
                "drug": normalized_drug,
                "original_drug": drug,
                "months": [str(m.strftime("%Y-%m")) for m in future_months],
                "predictions": forecast,
                "method": "minimal_data",
                "note": "Very limited data - using conservative projection"
            }

        # Prepare training data for StatsForecast
        # Ensure data is properly sorted and has no duplicates
        ddf = ddf.sort_values('month').drop_duplicates(subset=['month'], keep='last')
        
        # Fill missing months with interpolated values if gaps are small
        if len(ddf) > 1:
            # Create a complete date range
            min_date = ddf['month'].min()
            max_date = ddf['month'].max()
            full_range = pd.date_range(start=min_date, end=max_date, freq='ME')
        
            # Reindex to fill missing months
            ddf_indexed = ddf.set_index('month').reindex(full_range)
            
            # Interpolate missing values for small gaps (max 2 months)
            missing_count = ddf_indexed['quantity'].isna().sum()
            if missing_count > 0 and missing_count <= 2 and len(ddf) >= 3:
                ddf_indexed['quantity'] = ddf_indexed['quantity'].interpolate(method='linear', limit_direction='both')
                ddf_indexed['drug_name'] = ddf_indexed['drug_name'].ffill().bfill()
                ddf = ddf_indexed.reset_index().rename(columns={'index': 'month'})
                ddf = ddf.dropna(subset=['quantity'])
                print(f"Interpolated {missing_count} missing months for {normalized_drug}")
        
        sf_df = ddf.rename(columns={"month": "ds", "quantity": "y"}).copy()
        sf_df["unique_id"] = normalized_drug
        sf_df = sf_df[["unique_id", "ds", "y"]]

        # Ensure y values are numeric and positive
        sf_df['y'] = pd.to_numeric(sf_df['y'], errors='coerce')
        sf_df = sf_df.dropna(subset=['y'])
        sf_df['y'] = sf_df['y'].abs()  # Ensure positive values
        
        # Find saved model file
        model_path = find_model_file(normalized_drug)
        model_exists = model_path and os.path.exists(model_path)
        
        # Always retrain AutoETS with current data to ensure accuracy
        # The saved models indicate the drug has been modeled before, but we retrain with latest data
        sf_model = None
        
        try:
            if len(sf_df) >= 24:
                # Use AutoETS with seasonal pattern (12 months) for longer series (24+ months)
                sf_model = StatsForecast(models=[AutoETS(season_length=12)], freq="ME", n_jobs=1)
                sf_model = sf_model.fit(sf_df)
                print(f"Trained AutoETS model (seasonal, {len(sf_df)} months) for {normalized_drug}")
                # Save the model for future reference
                if model_path:
                    try:
                        os.makedirs(os.path.dirname(model_path), exist_ok=True)
                        joblib.dump(sf_model, model_path)
                        print(f"Saved model to {model_path}")
                    except Exception as e:
                        print(f"Could not save model: {e}")
                elif not model_exists:
                    # Create model path if not found
                    model_dir = f"{base}/models"
                    os.makedirs(model_dir, exist_ok=True)
                    default_model_path = f"{model_dir}/autoets_{normalized_drug.replace(' ', '_').replace('/', '_').replace('-', '_')}.pkl"
                    try:
                        joblib.dump(sf_model, default_model_path)
                        print(f"Saved model to {default_model_path}")
                    except Exception as e:
                        print(f"Could not save model: {e}")
            elif len(sf_df) >= 12:
                # Moderate data (12-23 months), use AutoETS without strong seasonality
                # Don't specify season_length parameter - let AutoETS decide automatically
                try:
                    sf_model = StatsForecast(models=[AutoETS()], freq="ME", n_jobs=1)
                    sf_model = sf_model.fit(sf_df)
                    print(f"Trained AutoETS model (auto-seasonal, {len(sf_df)} months) for {normalized_drug}")
                except (NotImplementedError, ValueError) as e:
                    if "tiny datasets" in str(e).lower():
                        print(f"AutoETS cannot handle {len(sf_df)} months of data for {normalized_drug}, will use fallback methods")
                        sf_model = None
                    else:
                        raise
            elif len(sf_df) >= 6:
                # Less data (6-11 months), use AutoETS without seasonality
                # AutoETS requires at least 6 months for meaningful predictions
                try:
                    sf_model = StatsForecast(models=[AutoETS()], freq="ME", n_jobs=1)
                    sf_model = sf_model.fit(sf_df)
                    print(f"Trained AutoETS model (non-seasonal, {len(sf_df)} months) for {normalized_drug}")
                except (NotImplementedError, ValueError) as e:
                    if "tiny datasets" in str(e).lower():
                        print(f"AutoETS cannot handle {len(sf_df)} months of data for {normalized_drug}, will use fallback methods")
                        sf_model = None
                    else:
                        raise
            else:
                # Very little data - use exponential smoothing instead
                sf_model = None
                print(f"Insufficient data for AutoETS ({len(sf_df) if len(sf_df) > 0 else 0} months) for {normalized_drug}, will use fallback methods")
        except Exception as e:
            print(f"Error training AutoETS model: {e}")
            import traceback
            traceback.print_exc()
            sf_model = None

        # Predict 3 months ahead using AutoETS if available
        if sf_model is not None:
            try:
                fc = sf_model.predict(h=3)
                # Extract predictions from forecast DataFrame
                if isinstance(fc, pd.DataFrame):
                    # Try different column names that StatsForecast might use
                    if "AutoETS" in fc.columns:
                        pred = fc["AutoETS"].values
                    elif len(fc.columns) > 0:
                        # Use first column
                        pred = fc.iloc[:, 0].values
                    else:
                        pred = None
                elif hasattr(fc, 'values'):
                    pred = fc.values
                else:
                    pred = None
                
                # Validate predictions
                if pred is not None and len(pred) >= 3:
                    pred = np.array(pred[:3])  # Ensure exactly 3 predictions
                    # Check if all predictions are valid numbers (finite and positive)
                    if np.all(np.isfinite(pred)):
                        # Replace any non-positive values before final check
                        if np.any(pred <= 0):
                            # Use historical data to replace zeros
                            min_val = max(historical_mean * 0.3 if historical_mean else 10, 10)
                            pred = np.where(pred <= 0, min_val, pred)
                        
                        # Final validation - ensure we have valid positive predictions
                        # Convert to float to handle decimal.Decimal types
                        pred = pred.astype(float)
                        if np.all(pred > 0):
                            # Check if predictions are too similar (within 0.1%)
                            if len(pred) == 3 and np.allclose(pred, pred[0], rtol=0.001):
                                # AutoETS produced constant predictions, add trend-based variation
                                # Use historical trend to add variation
                                if len(values) >= 3:
                                    recent_trend = float((values[-1] - values[-min(3, len(values))]) / min(3, len(values)))
                                    if abs(recent_trend) < 0.01:  # No clear trend
                                        # Add slight growth variation (1-3% per month)
                                        growth_variation = float(pred[0]) * 0.015  # 1.5% variation
                                        pred = [float(pred[0]) + growth_variation * (i+1) for i in range(3)]
                                        method = "autoets_with_trend"
                                    else:
                                        # Apply detected trend
                                        pred = [float(pred[0]) + float(recent_trend) * (i+1) for i in range(3)]
                                        method = "autoets_with_trend"
                                else:
                                    # Add slight growth (1-2% per month)
                                    growth_rate = 0.015
                                    pred = [float(pred[0]) * (1 + growth_rate * (i+1)) for i in range(3)]
                                    method = "autoets_with_growth"
                            else:
                                method = "autoets"
                        else:
                            # Still has issues, use fallback
                            pred = None
                            method = "autoets_invalid"
                    else:
                        pred = None
                        method = "autoets_invalid"
                else:
                    pred = None
                    method = "autoets_empty"
            except Exception as e:
                print(f"Error making prediction with AutoETS: {e}")
                import traceback
                traceback.print_exc()
                pred = None
                method = "autoets_error"
        else:
            pred = None
            method = None
        
        # Fallback to exponential smoothing if AutoETS fails or insufficient data
        # Convert pred to float array if it exists, then check conditions
        if pred is None:
            should_use_fallback = True
        else:
            try:
                pred_array = np.array(pred, dtype=float)
                should_use_fallback = len(pred_array) == 0 or np.all(pred_array <= 0) or not np.all(np.isfinite(pred_array))
            except (TypeError, ValueError):
                should_use_fallback = True
        
        if should_use_fallback:
            try:
                if len(values) >= 6:
                    # Use Holt-Winters exponential smoothing with trend
                    try:
                        model = ExponentialSmoothing(values, trend='add', seasonal=None, seasonal_periods=None)
                        fitted_model = model.fit(optimized=True)
                        pred = fitted_model.forecast(3)
                        method = "holt_winters"
                        # Add some natural variability to avoid flat predictions
                        # Convert to float to handle decimal.Decimal types
                        pred = np.array(pred, dtype=float)
                        if len(pred) == 3 and np.allclose(pred, pred[0], rtol=1e-5):
                            # Predictions are too similar, add trend-based variation
                            trend = float((values[-1] - values[-3]) / 3) if len(values) >= 3 else 0.0
                            if abs(trend) < 0.01:  # No significant trend, add slight growth
                                trend = float(pred[0]) * 0.02  # 2% monthly growth
                            pred = [float(pred[0]) + float(trend) * (i+1) for i in range(3)]
                    except:
                        # If Holt-Winters fails, use additive trend with simple smoothing
                        trend = float(values[-1] - values[0]) / max(len(values) - 1, 1)
                        if abs(trend) < 0.01:  # No clear trend, use weighted average with slight growth
                            recent_avg = float(np.mean(values[-3:]) if len(values) >= 3 else values[-1])
                            growth_rate = 0.015  # 1.5% monthly growth
                            pred = [float(recent_avg * (1 + growth_rate * (i+1))) for i in range(3)]
                        else:
                            last_val = float(values[-1])
                            trend_float = float(trend)
                            mean_val = float(historical_mean * 0.7) if historical_mean else 10.0
                            pred = [float(max(last_val + trend_float * (i+1), mean_val)) 
                                    for i in range(3)]
                        method = "trend_projection"
                elif len(values) >= 3:
                    # Calculate trend from recent values
                    recent_values = values[-3:]
                    trend = float((recent_values[-1] - recent_values[0]) / len(recent_values))
                    
                    # If no clear trend, use weighted average with slight growth
                    if abs(trend) < 0.01:
                        recent_avg = float(np.mean(recent_values))
                        growth_rate = 0.02  # 2% monthly growth
                        pred = [float(recent_avg * (1 + growth_rate * (i+1))) for i in range(3)]
                    else:
                        # Apply trend projection
                        last_val = float(values[-1])
                        mean_val = float(historical_mean * 0.7) if historical_mean else 10.0
                        pred = [float(max(last_val + trend * (i+1), mean_val)) 
                                for i in range(3)]
                    method = "trend_projection"
                elif len(values) >= 2:
                    # Simple trend between two points
                    trend = float(values[-1] - values[0])
                    last_val = float(values[-1])
                    mean_val = float(historical_mean * 0.7) if historical_mean else 10.0
                    pred = [float(max(last_val + trend * (i+1), mean_val)) 
                            for i in range(3)]
                    method = "linear_projection"
                else:
                    # Use mean or last value with natural growth variation
                    base_val = float(historical_mean if historical_mean else (last_value if last_value else 50))
                    # Add slight increasing trend with some variability
                    growth_rate = 0.025  # 2.5% monthly growth
                    pred = [float(max(base_val * (1 + growth_rate * (i+1) + 0.01 * i), 10)) for i in range(3)]
                    method = "growth_projection"
            except Exception as e:
                print(f"Fallback prediction error: {e}")
                import traceback
                traceback.print_exc()
                # Ultimate fallback: use historical mean or last value with growth
                base_pred = float(max(historical_mean if historical_mean else (last_value if last_value else 50), 10))
                growth_rate = 0.02  # 2% monthly growth
                pred = [float(base_pred * (1 + growth_rate * (i+1))) for i in range(3)]
                method = "mean_based_growth"
        
        # Ensure all predictions are positive and realistic
        pred = ensure_positive_predictions(pred, historical_mean, last_value)
        
        # Convert to float list to handle decimal.Decimal types from database
        pred = [float(p) for p in pred]
        
        # Final check: Ensure predictions show variation (not all identical)
        if len(pred) == 3 and np.allclose(np.array(pred, dtype=float), pred[0], rtol=0.001):
            # All predictions are nearly identical, add natural variation
            if historical_mean and historical_mean > 0:
                # Use coefficient of variation if available
                if historical_std and historical_std > 0:
                    cv = historical_std / historical_mean
                    variation = pred[0] * min(cv, 0.1)  # Max 10% variation
                else:
                    variation = pred[0] * 0.02  # 2% variation
                
                # Add slight trend-based variation (increasing trend)
                # Ensure the trend is positive to show growth
                pred = [pred[0] + variation * i for i in range(3)]
            else:
                # Add slight increasing trend (1-2% per month)
                growth = pred[0] * 0.015
                pred = [pred[0] + growth * i for i in range(3)]
            
            print(f"Added variation to predictions for {normalized_drug} to avoid flat forecast")
            if method == "autoets" or method == "exponential_smoothing":
                method = method + "_with_variation"

        last_date = ddf["month"].max()
        future_months = pd.date_range(last_date, periods=4, freq="ME")[1:]

        # Ensure all return values are proper types (float for numeric values)
        return {
            "drug": normalized_drug,  # Return normalized name
            "original_drug": drug,  # Keep original for reference
            "months": [str(m.strftime("%Y-%m")) for m in future_months],
            "predictions": [float(p) for p in pred],  # Ensure all predictions are floats
            "method": method,
            "historical_mean": float(historical_mean) if historical_mean is not None else None,
            "last_value": float(last_value) if last_value is not None else None,
            "note": "Using synthetic data based on current stock" if using_synthetic else None
        }
    except Exception as e:
        # Catch any unhandled exceptions and return a proper error response
        print(f"Unhandled error in predict_autoets: {e}")
        import traceback
        traceback.print_exc()
        # Return proper HTTP error response
        raise HTTPException(
            status_code=500,
            detail={
                "error": f"Internal server error: {str(e)}",
                "drug": drug if 'drug' in locals() else "unknown",
                "suggestion": "Please check server logs for more details"
            }
        )
