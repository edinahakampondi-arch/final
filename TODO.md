# Comprehensive System Fix & Refactor Plan

## 1. Database Schema & Logic Fixes

- [ ] Remove min_stock, max_stock, stock_level fields from drugs table
- [ ] Add triggers/procedures to update current_stock based on drug_checkouts and borrowing_requests
- [ ] Ensure current_stock is not manually editable
- [ ] Update all PHP queries to remove min_stock/max_stock references
- [ ] Fix stock calculations in department dashboards

## 2. Prediction Backend (AutoETS)

- [ ] Update app.py to use AutoETS instead of AutoARIMA
- [ ] Add fallback for drugs with <6 months data (SES, mean, or constant)
- [ ] Ensure /predict/{drug} returns valid JSON for any dataset size
- [ ] Test prediction API endpoints

## 3. Frontend Modularization

- [ ] Create includes/header.php with common header/navigation
- [ ] Create includes/footer.php with common footer
- [ ] Create includes/sidebar.php for navigation
- [ ] Update all dashboard PHP files to use includes
- [ ] Remove duplicated scripts and styles
- [ ] Fix broken links and navigation

## 4. Admin Dashboard Enhancements

- [ ] Add prediction tab showing drugs with highest predicted demand
- [ ] Integrate AutoETS API calls for forecast data
- [ ] Fix AJAX/JS prediction handlers
- [ ] Ensure all cards show real database values
- [ ] Add forecast history table

## 5. Department Dashboard Filtering

- [ ] Verify all department dashboards filter data by department
- [ ] Ensure transactions, stock, alerts are department-specific
- [ ] Update queries to use WHERE department = '$user_department'
- [ ] Test filtering for all departments

## 6. Prediction Integration

- [ ] Fix dropdown/autocomplete for drug search
- [ ] Ensure prediction button opens modal correctly
- [ ] Display forecast results in dashboard
- [ ] Update prediction history table with demand data

## 7. Testing & Validation

- [ ] Test FastAPI server startup
- [ ] Test prediction API with various drugs
- [ ] Test database stock updates
- [ ] Test all dashboard functionalities
- [ ] Verify no JS errors or broken links
