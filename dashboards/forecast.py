from flask import Flask, request, jsonify
from flask_cors import CORS
import pandas as pd
import numpy as np
import mysql.connector
from tensorflow.keras.models import load_model
import joblib
import datetime
import logging

app = Flask(__name__)
CORS(app)  # Enable CORS for all routes

# Set up logging
logging.basicConfig(level=logging.DEBUG, format='%(asctime)s - %(levelname)s - %(message)s')

# Load the LSTM model and scaler
try:
    model = load_model("all_drugs_lstm_model.h5")
    scaler = joblib.load("scaler_all_drugs.joblib")
except Exception as e:
    logging.error(f"Failed to load model or scaler: {str(e)}")
    raise

def get_db_connection():
    try:
        return mysql.connector.connect(
            host="localhost",
            user="edina",  # Replace with your MySQL username
            password="123",  # Replace with your MySQL password
            database="system"
        )
    except mysql.connector.Error as e:
        logging.error(f"Database connection failed: {str(e)}")
        raise

@app.route('/forecast', methods=['POST'])
def forecast():
    try:
        data = request.json
        logging.debug(f"Received payload: {data}")
        drug_id = data.get("drug_id")
        drug_name = data.get("drug_name")
        department = data.get("department")

        if not drug_id or not drug_name or not department:
            logging.warning("Missing required fields in payload")
            return jsonify({"error": "Missing required fields"}), 400

        # Pull historical usage data from DB
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""SELECT t.checkout_time AS Date, t.quantity_dispensed AS QuantityDispensed, d.current_stock AS StockOnHand
                            FROM drug_checkouts t
                            INNER JOIN drugs d ON t.id = d.id
                            WHERE t.id = %s AND t.Department = %s
                            ORDER BY d.expiry_date
                        """, (drug_id, department))
        rows = cursor.fetchall()
        logging.debug(f"Database rows fetched: {len(rows)} rows")
        conn.close()

        if not rows:
            logging.warning(f"No historical data found for DrugID={drug_id}, Department={department}")
            return jsonify({"error": "No historical data found"}), 404

        # Prepare data for prediction
        df = pd.DataFrame(rows)
        values = df["QuantityDispensed"].values.reshape(-1, 1)
        logging.debug(f"Raw data shape: {values.shape}")

        # Scale the data
        scaled_values = scaler.transform(values)
        logging.debug(f"Scaled data shape: {scaled_values.shape}")

        # Ensure at least 30 data points for the sequence
        if len(scaled_values) < 30:
            logging.warning(f"Insufficient data points: {len(scaled_values)}. Padding with zeros.")
            padding = np.zeros((30 - len(scaled_values), 1))
            scaled_values = np.vstack((padding, scaled_values))

        # Prepare input sequence (last 30 days)
        sequence = np.array([scaled_values[-30:]])
        logging.debug(f"Input sequence shape: {sequence.shape}")

        # Make prediction
        y_pred_scaled = model.predict(sequence)
        logging.debug(f"Prediction scaled shape: {y_pred_scaled.shape}")

        # Inverse scale to original values
        y_pred = scaler.inverse_transform(y_pred_scaled)
        logging.debug(f"Prediction unscaled shape: {y_pred.shape}")

        # Generate forecast results
        forecast_results = []
        today = datetime.date.today()
        for i, qty in enumerate(y_pred[0]):
            forecast_results.append({
                "DrugID": drug_id,
                "DrugName": drug_name,
                "Department": department,
                "ForecastDate": (today + datetime.timedelta(days=i+1)).isoformat(),
                "PredictedDemand": int(max(0, qty))  # Ensure no negative values
            })

        # Save forecasts to MySQL
        conn = get_db_connection()
        cursor = conn.cursor()
        for f in forecast_results:
            cursor.execute("""
                INSERT INTO Forecasts (DrugID, DrugName, Department, ForecastDate, PredictedDemand)
                VALUES (%s, %s, %s, %s, %s)
            """, (f["DrugID"], f["DrugName"], f["Department"], f["ForecastDate"], f["PredictedDemand"]))
        conn.commit()
        conn.close()
        logging.debug("Forecasts saved to database")

        return jsonify({"message": "Forecasts saved successfully", "forecasts": forecast_results})

    except mysql.connector.Error as db_error:
        logging.error(f"Database error: {str(db_error)}")
        return jsonify({"error": f"Database error: {str(db_error)}"}), 500
    except Exception as e:
        logging.error(f"Unexpected error in forecast: {str(e)}")
        return jsonify({"error": f"Unexpected error: {str(e)}"}), 500

if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5000, debug=True)