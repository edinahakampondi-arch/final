# Forecasting Tab Logic Documentation

## Overview

The forecasting tab in this project provides drug demand predictions for the next 3 months. It uses a combination of FastAPI backend (Python) for prediction generation and PHP frontend (JavaScript/jQuery) for data display. The system supports both individual department views and an admin view showing all departments.

---

## Architecture Overview

```
┌─────────────────┐
│  Dashboard PHP  │
│   (Frontend)    │
└────────┬────────┘
         │
         │ AJAX Request
         ▼
┌─────────────────────────┐
│ get_forecast_drugs.php  │
│   (Backend PHP)         │
└────────┬────────────────┘
         │
         │ HTTP GET Request
         ▼
┌─────────────────────────┐
│   FastAPI Server        │
│    (app.py)             │
│   Port: 8000            │
└────────┬────────────────┘
         │
         │ SQL Query
         ▼
┌─────────────────────────┐
│   MySQL Database        │
│  (drug_checkouts table) │
└─────────────────────────┘
```

---

## Backend Logic (FastAPI - `app.py`)

### 1. API Endpoint

**Endpoint**: `GET /predict/{drug}`

**Location**: `app.py`, function `predict_autoets()`

**Purpose**: Generates 3-month demand forecasts for a specific drug.

### 2. Request Flow

#### Step 1: Drug Name Normalization
```python
normalized_drug = normalize_drug_name(drug)
```
- Decodes URL encoding (e.g., `+` becomes space, `%20` becomes space)
- Trims whitespace
- Normalizes multiple spaces to single space
- Example: `"Amlodipine%2B5%2Bmg"` → `"Amlodipine 5 mg"`

#### Step 2: Data Retrieval
```python
ddf = get_monthly_demand_from_db(normalized_drug)
```

The `get_monthly_demand_from_db()` function:
1. **Connects to MySQL database** using pymysql
2. **Checks table structure** dynamically using `DESCRIBE drug_checkouts` to handle schema variations:
   - Option A: `drug_id` + `quantity_dispensed` (joins with `drugs` table)
   - Option B: `drug_name` + `quantity` (direct column access)
3. **Queries historical checkout data** from the last 24 months:
   ```sql
   SELECT 
       CONCAT(YEAR(checkout_time), '-', LPAD(MONTH(checkout_time), 2, '0'), '-01') as month,
       SUM(quantity_dispensed) as quantity
   FROM drug_checkouts
   WHERE checkout_time >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
       AND drug_name = ?
   GROUP BY YEAR(checkout_time), MONTH(checkout_time)
   ```
4. **Returns pandas DataFrame** with columns: `month`, `drug_name`, `quantity`

#### Step 3: Data Validation and Preprocessing

**If no historical data found:**
- Checks if drug exists in `drugs` table using `get_drug_info_from_db()`
- If exists, generates **synthetic historical data** based on current stock:
  ```python
  estimated_monthly_demand = current_stock * 0.25  # 20-40% of stock
  ```
- Creates 6 months of synthetic data with natural variation:
  - Trend factor: 3% per month
  - Random noise: ±8%
  - Cyclical pattern: 5% sinusoidal variation

**Data preprocessing:**
- Sorts by month and removes duplicates
- Fills missing months with linear interpolation (if gaps ≤ 2 months)
- Converts `quantity` to numeric (handles `decimal.Decimal` from MySQL)
- Removes NaN values

#### Step 4: Model Selection and Training

The system uses a hierarchical approach based on data availability:

**For 24+ months of data:**
```python
sf_model = StatsForecast(
    models=[AutoETS(season_length=12)],  # Seasonal pattern
    freq="ME",  # Monthly frequency
    n_jobs=1
)
```
- Uses **AutoETS with 12-month seasonality** (captures yearly patterns)
- Trains on all available data
- Saves model to `Drug_Demand_Prediction/models/autoets_{drug_name}.pkl`

**For 12-23 months of data:**
```python
sf_model = StatsForecast(
    models=[AutoETS()],  # Auto-seasonality
    freq="ME"
)
```
- Uses **AutoETS with automatic seasonality detection**
- Lets the model decide optimal seasonal patterns

**For 6-11 months of data:**
```python
sf_model = StatsForecast(
    models=[AutoETS()],  # Non-seasonal
    freq="ME"
)
```
- Uses **AutoETS without strong seasonality**
- Minimum data requirement: 6 months

**For < 6 months of data:**
- Skips AutoETS (insufficient data)
- Falls back to exponential smoothing or trend projection

#### Step 5: Prediction Generation

**If AutoETS model available:**
```python
fc = sf_model.predict(h=3)  # 3 months ahead
pred = fc["AutoETS"].values  # Extract predictions
```

**Fallback Methods:**

1. **Holt-Winters Exponential Smoothing** (≥6 months):
   ```python
   model = ExponentialSmoothing(
       values, 
       trend='add', 
       seasonal=None
   )
   fitted_model = model.fit(optimized=True)
   pred = fitted_model.forecast(3)
   ```

2. **Trend Projection** (3-5 months):
   - Calculates linear trend: `trend = (last_value - first_value) / period`
   - Projects forward: `pred[i] = last_value + trend * (i+1)`

3. **Growth Projection** (2 months):
   - Uses historical mean with 2.5% monthly growth
   - Adds natural variation

4. **Minimal Data Fallback** (<2 months):
   - Uses last value or mean with conservative projection
   - Applies 10-20% reduction factor

#### Step 6: Post-Processing

1. **Ensures positive predictions**: Replaces zeros/negatives with minimum value (30% of historical mean or 10)
2. **Adds natural variation** if predictions are too flat (within 0.1% similarity):
   - Uses historical coefficient of variation
   - Applies 1-2% growth trend
3. **Converts to float**: Handles `decimal.Decimal` types from database
4. **Validates predictions**: Ensures all values are finite and positive

#### Step 7: Response Format

```json
{
    "drug": "Amlodipine 5 mg Tablets",
    "original_drug": "Amlodipine%2B5%2Bmg",
    "months": ["2025-11", "2025-12", "2026-01"],
    "predictions": [145.2, 148.5, 151.8],
    "method": "autoets",
    "historical_mean": 143.5,
    "last_value": 147.0,
    "note": null
}
```

**Error Response:**
```json
{
    "error": "No historical data found for drug: X",
    "drug": "X",
    "suggestion": "Ensure the drug name matches exactly...",
    "similar_drugs": ["Drug A", "Drug B"]
}
```

### 3. Database Functions

#### `get_monthly_demand_from_db(drug_name)`
- **Purpose**: Retrieves monthly aggregated checkout data
- **Returns**: pandas DataFrame with monthly quantities
- **Schema Detection**: Dynamically detects table structure
- **Name Matching**: Supports exact and fuzzy matching (LIKE query)

#### `get_drug_info_from_db(drug_name)`
- **Purpose**: Retrieves current drug information
- **Returns**: Dictionary with `drug_name`, `current_stock`, `department`, `expiry_date`
- **Used For**: Generating synthetic data when no checkout history exists

---

## Frontend Logic (PHP/JavaScript)

### 1. Forecast Tab Structure

**Location**: `dashboards/{Department}.php` (e.g., `Paediatrics.php`, `surgery.php`)

**HTML Structure:**
```html
<section id="forecasting" class="tab-content hidden">
    <div id="forecast-loading">⏳ Loading...</div>
    <div id="forecast-results" class="hidden">
        <table>
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Drug Name</th>
                    <th>Current Stock</th>
                    <th>Month 1</th>
                    <th>Month 2</th>
                    <th>Month 3</th>
                    <th>Total (3 Months)</th>
                    <th>Method</th>
                </tr>
            </thead>
            <tbody id="forecast-table-body"></tbody>
        </table>
    </div>
</section>
```

### 2. Tab Click Handler

**Location**: JavaScript section in each department dashboard

**Trigger**: User clicks the "Forecasting" tab button

```javascript
document.querySelectorAll('.tab-button').forEach(button => {
    button.addEventListener('click', function() {
        const tabId = button.getAttribute('data-tab');
        // Show selected tab, hide others
        if (tabId === 'forecasting') {
            loadForecast();  // Load forecast data
        }
    });
});
```

### 3. `loadForecast()` Function

**Location**: Each department dashboard JavaScript section

**Purpose**: Fetches and displays forecast data via AJAX

```javascript
function loadForecast() {
    // Show loading indicator
    $('#forecast-loading').removeClass('hidden');
    $('#forecast-results').addClass('hidden');
    $('#forecast-empty').addClass('hidden');
    
    $.ajax({
        url: 'get_forecast_drugs.php',
        type: 'GET',
        dataType: 'json',
        timeout: 120000,  // 2 minutes (5 minutes for admin)
        success: function(response) {
            // Handle success...
        },
        error: function(xhr, status, error) {
            // Handle error...
        }
    });
}
```

### 4. Backend PHP Script: `get_forecast_drugs.php`

**Location**: `dashboards/get_forecast_drugs.php`

**Purpose**: Acts as a bridge between frontend and FastAPI, aggregates predictions for multiple drugs

#### Step 1: Authentication & Authorization
```php
session_start();
if (!isset($_SESSION['department'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
```

#### Step 2: Department-Specific Drug Retrieval

**For Regular Departments:**
```php
SELECT drug_name, department, MAX(current_stock) as current_stock 
FROM drugs 
WHERE department = ? 
GROUP BY drug_name 
ORDER BY drug_name ASC
```
- Returns all unique drugs for the logged-in department
- Uses `MAX(current_stock)` to handle duplicates

**For Admin:**
```php
SELECT drug_name, department, MAX(current_stock) as current_stock 
FROM drugs 
WHERE department IN ('Surgery', 'Paediatrics', 'Internal medicine', 'Intensive Care unit', 'Gynaecology')
GROUP BY drug_name, department 
ORDER BY department, drug_name ASC
```
- Returns drugs from all 5 target departments
- Groups by both `drug_name` and `department` to show department-specific entries

#### Step 3: Drug Deduplication

**Logic:**
- For admin: Uses `drug_name|department` as unique key
- For departments: Uses `drug_name` as unique key
- If duplicate found, keeps entry with higher `current_stock`

#### Step 4: Prediction Processing

**For Admin:**
1. **Groups drugs by department**:
   ```php
   $drugs_by_dept[$dept][] = $drug;
   ```
2. **Processes each department separately**:
   - Processes up to 20 drugs per department
   - Gets predictions via `getPrediction()` function
   - Sorts by `total_demand` and keeps top 15 per department
   - Total maximum: 75 forecasts (15 × 5 departments)
   - Delay: 25ms between API calls

**For Regular Departments:**
- Processes up to 50 drugs sequentially
- Gets predictions via `getPrediction()` function
- Returns top 50 sorted by `total_demand`
- Delay: 50ms between API calls

#### Step 5: `getPrediction()` Function

```php
function getPrediction($drug_name) {
    $api_url = "http://127.0.0.1:8000/predict/" . urlencode($drug_name);
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,  // 15 seconds per drug
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($api_url, false, $context);
    // ... error handling ...
    
    // Extract 3-month predictions
    $total_demand = array_sum(array_slice($data['predictions'], 0, 3));
    return [
        'total_demand' => round($total_demand, 2),
        'month1' => round($data['predictions'][0], 2),
        'month2' => round($data['predictions'][1], 2),
        'month3' => round($data['predictions'][2], 2),
        'method' => $data['method'],
        'historical_mean' => $data['historical_mean']
    ];
}
```

**Error Handling:**
- Returns `null` if API call fails
- Logs errors to `php_errors.log`
- Continues processing other drugs even if one fails

#### Step 6: Final Sorting and Response

```php
// Sort all forecasts by total demand (descending)
usort($forecasts, function($a, $b) {
    return $b['total_demand'] <=> $a['total_demand'];
});

// Return top N (75 for admin, 50 for departments)
$max_results = $is_admin ? 75 : 50;
$forecasts = array_slice($forecasts, 0, $max_results);

echo json_encode(['forecasts' => $forecasts]);
```

### 5. Frontend Display Logic

**Location**: `loadForecast()` success callback in each dashboard

#### Step 1: Error Handling
```javascript
if (response.error) {
    $('#forecast-table-body').html(
        `<tr><td colspan="8">${response.error}</td></tr>`
    );
    return;
}

if (!response.forecasts || response.forecasts.length === 0) {
    $('#forecast-empty').removeClass('hidden');
    return;
}
```

#### Step 2: Table Generation
```javascript
let html = '';
response.forecasts.forEach((forecast, index) => {
    // Clean drug name (remove "Suppository" if present)
    const cleanName = forecast.drug_name
        .replace(/Suppository/gi, '')
        .trim();
    
    // Determine stock status class
    const stockClass = forecast.current_stock < forecast.total_demand
        ? 'text-red-600 font-bold'  // Low stock warning
        : 'text-gray-800';
    
    // Determine method badge color
    let methodBadge = 'bg-gray-100 text-gray-800';
    const method = (forecast.method || '').toLowerCase();
    if (method.includes('autoets')) {
        methodBadge = 'bg-purple-100 text-purple-800';
    } else if (method.includes('holt') || method.includes('winters')) {
        methodBadge = 'bg-blue-100 text-blue-800';
    } else if (method.includes('trend') || method.includes('projection')) {
        methodBadge = 'bg-green-100 text-green-800';
    }
    
    // Format method name for display
    const methodDisplay = forecast.method
        .replace(/_/g, ' ')
        .replace(/\b\w/g, l => l.toUpperCase());
    
    // Build table row
    html += `
        <tr class="hover:bg-gray-50">
            <td>${index + 1}</td>
            <td>${escapeHtml(cleanName)}</td>
            <td class="${stockClass}">${forecast.current_stock}</td>
            <td>${forecast.month1}</td>
            <td>${forecast.month2}</td>
            <td>${forecast.month3}</td>
            <td class="font-bold text-blue-600">${forecast.total_demand}</td>
            <td><span class="${methodBadge}">${methodDisplay}</span></td>
        </tr>
    `;
});

$('#forecast-table-body').html(html);
$('#forecast-results').removeClass('hidden');
```

#### Step 3: Visual Indicators

**Stock Status:**
- **Red & Bold**: Current stock < Total predicted demand (3 months)
- **Gray**: Current stock sufficient

**Method Badges:**
- **Purple**: AutoETS models (most accurate)
- **Blue**: Holt-Winters / Exponential Smoothing
- **Green**: Trend/Linear/Growth Projection
- **Yellow**: Minimal data / Mean-based methods
- **Gray**: Unknown method

### 6. Performance Optimizations

1. **Timeouts**:
   - Regular departments: 2 minutes (120,000ms)
   - Admin: 5 minutes (300,000ms)

2. **Execution Limits**:
   - PHP `set_time_limit(300)` for 5-minute script execution

3. **API Delays**:
   - Regular departments: 50ms between calls
   - Admin: 25ms between calls (faster processing)

4. **Result Limits**:
   - Regular departments: Top 50 drugs
   - Admin: Top 15 per department (75 total)

5. **Parallel Processing**:
   - Drugs are processed sequentially (not parallel) to avoid overwhelming the API
   - Errors in one drug don't stop processing of others

---

## Data Flow Diagram

```
User clicks "Forecasting" tab
         │
         ▼
JavaScript: loadForecast() called
         │
         ▼
AJAX GET: get_forecast_drugs.php
         │
         ├─► PHP: Check session/auth
         ├─► PHP: Query drugs from database
         ├─► PHP: For each drug:
         │      │
         │      ├─► getPrediction(drug_name)
         │      │      │
         │      │      ├─► HTTP GET: http://127.0.0.1:8000/predict/{drug}
         │      │      │      │
         │      │      │      ├─► FastAPI: normalize_drug_name()
         │      │      │      ├─► FastAPI: get_monthly_demand_from_db()
         │      │      │      │      │
         │      │      │      │      └─► MySQL: Query drug_checkouts
         │      │      │      │
         │      │      │      ├─► FastAPI: Train AutoETS/fallback model
         │      │      │      ├─► FastAPI: Generate 3-month predictions
         │      │      │      └─► FastAPI: Return JSON response
         │      │      │
         │      │      └─► PHP: Extract predictions, calculate total
         │      │
         │      └─► Continue to next drug
         │
         ├─► PHP: Sort forecasts by total_demand
         ├─► PHP: Return top N forecasts as JSON
         │
         ▼
JavaScript: Success callback
         │
         ├─► Generate HTML table rows
         ├─► Apply styling (stock status, method badges)
         └─► Display in forecast-results div
```

---

## Error Handling

### Backend (FastAPI)

1. **Database Connection Errors**:
   - Returns 500 with error message
   - Logs to console

2. **No Historical Data**:
   - Attempts synthetic data generation
   - Returns error with suggestions if drug doesn't exist

3. **Model Training Errors**:
   - Falls back to simpler methods (Holt-Winters → Trend → Growth)
   - Always returns valid predictions

4. **Type Conversion Errors**:
   - Explicit `float()` conversions handle `decimal.Decimal` types
   - `np.array(..., dtype=float)` ensures NumPy compatibility

### Frontend (PHP)

1. **API Call Failures**:
   - Logs error to `php_errors.log`
   - Returns `null` and continues processing other drugs
   - Final response includes only successful predictions

2. **Database Errors**:
   - Returns JSON error response
   - Logs detailed error information

3. **Timeout Errors**:
   - AJAX timeout triggers error callback
   - Displays error message in table
   - Logs to browser console

### Frontend (JavaScript)

1. **AJAX Errors**:
   ```javascript
   error: function(xhr, status, error) {
       $('#forecast-loading').addClass('hidden');
       $('#forecast-results').removeClass('hidden');
       $('#forecast-table-body').html(
           `<tr><td colspan="8">Error: ${error}</td></tr>`
       );
       console.error('Forecast load error:', status, error, xhr.responseText);
   }
   ```

2. **Empty Results**:
   - Shows "No forecast data available" message
   - Hides table, shows empty state

---

## Key Features

### 1. **Adaptive Model Selection**
- Automatically selects the best forecasting method based on available data
- Prioritizes accuracy (AutoETS > Holt-Winters > Trend > Growth)

### 2. **Synthetic Data Generation**
- Generates realistic historical data when no checkout history exists
- Uses current stock as basis for estimation
- Applies natural variation (trend, noise, cycles)

### 3. **Department-Specific Views**
- Regular departments: See only their drugs
- Admin: See top 15 drugs from each of 5 departments
- Maintains department context in all views

### 4. **Performance Optimization**
- Limits processing to prevent timeouts
- Uses delays to avoid API overload
- Sorts and filters before returning results

### 5. **User-Friendly Display**
- Color-coded stock warnings (red for low stock)
- Method badges indicate prediction quality
- Clean drug names (removes "Suppository" text)
- Ranked by total predicted demand

---

## Configuration

### FastAPI Server
- **Port**: 8000
- **Host**: 127.0.0.1 (localhost)
- **URL Pattern**: `http://127.0.0.1:8000/predict/{drug_name}`
- **Start Command**: `uvicorn app:app --host 0.0.0.0 --port 8000`

### Database
- **Connection**: MySQL/MariaDB via pymysql
- **Tables Used**:
  - `drugs`: Current drug information
  - `drug_checkouts`: Historical checkout data
- **Key Columns**:
  - `drug_checkouts`: `checkout_time`, `quantity_dispensed` (or `quantity`), `drug_id` (or `drug_name`)
  - `drugs`: `drug_name`, `current_stock`, `department`, `expiry_date`

### PHP Configuration
- **Execution Time**: 300 seconds (5 minutes)
- **Error Logging**: `dashboards/php_errors.log`
- **Session Required**: User must be logged in with valid department

---

## Troubleshooting

### Common Issues

1. **"Error loading forecast: timeout"**
   - **Cause**: Too many drugs to process within timeout limit
   - **Solution**: Reduce `max_drugs` limit or increase timeout

2. **"No historical data found"**
   - **Cause**: Drug has no checkout history
   - **Solution**: System should generate synthetic data automatically

3. **"Error: Could not connect to prediction API"**
   - **Cause**: FastAPI server not running
   - **Solution**: Start server with `uvicorn app:app --host 0.0.0.0 --port 8000`

4. **Empty forecast table**
   - **Cause**: No drugs in database or all predictions failed
   - **Solution**: Check `php_errors.log` for API call failures

5. **TypeError: unsupported operand type(s)**
   - **Cause**: Decimal type from MySQL not converted to float
   - **Solution**: Already handled with explicit `float()` conversions

---

## Future Enhancements

1. **Caching**: Cache predictions for frequently accessed drugs
2. **Parallel Processing**: Use async requests to speed up admin view
3. **Model Persistence**: Save and reuse trained models across requests
4. **Batch API**: Single endpoint for multiple drugs
5. **Real-time Updates**: WebSocket notifications for new predictions
6. **Export Functionality**: Download forecasts as CSV/PDF
7. **Historical Comparison**: Show prediction accuracy over time

---

## File Structure

```
Final/
├── app.py                              # FastAPI backend (prediction logic)
├── dashboards/
│   ├── get_forecast_drugs.php         # PHP bridge (aggregates predictions)
│   ├── admin_dashboard.php            # Admin view
│   ├── Paediatrics.php                # Department view
│   ├── surgery.php                    # Department view
│   ├── gynaecology.php                # Department view
│   ├── Internal medicine.php          # Department view
│   └── Intensive Care unit.php        # Department view
└── Drug_Demand_Prediction/
    └── models/                        # Saved AutoETS models
        └── autoets_{drug_name}.pkl
```

---

## Conclusion

The forecasting tab provides a comprehensive drug demand prediction system that:
- Adapts to available data using multiple forecasting methods
- Handles edge cases (missing data, errors, timeouts)
- Optimizes performance for both individual and admin views
- Provides clear visual feedback to users
- Maintains scalability and reliability

The architecture separates concerns cleanly: FastAPI handles prediction logic, PHP handles aggregation and session management, and JavaScript handles user interaction and display.

