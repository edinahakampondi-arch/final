# Drug Inventory Management System - Project Documentation

## Table of Contents

1. [Project Overview](#project-overview)
2. [System Architecture](#system-architecture)
3. [Dashboard Features](#dashboard-features)
   - [Admin Dashboard](#admin-dashboard)
   - [Department Dashboards](#department-dashboards)
4. [Key Functionalities](#key-functionalities)
5. [Technology Stack](#technology-stack)
6. [Recommendations for Improvement](#recommendations-for-improvement)

---

## Project Overview

The Drug Inventory Management System is a comprehensive web-based application designed to manage pharmaceutical inventory across multiple hospital departments. The system provides real-time tracking, automated alerts, demand forecasting using AI/ML models, and inter-departmental drug borrowing capabilities.

### Key Objectives

- Centralized drug inventory management
- Real-time stock monitoring and alerts
- AI-powered demand forecasting
- Inter-departmental resource sharing
- Expiry date tracking and management
- Comprehensive audit trails

---

## System Architecture

### Backend Components

- **PHP 7.4+**: Server-side logic and business rules
- **MySQL Database**: Data persistence (MariaDB compatible)
- **FastAPI (Python)**: AI/ML prediction service
- **WebSocket**: Real-time updates for notifications

### Frontend Components

- **HTML5/CSS3**: Structure and styling
- **Tailwind CSS**: Utility-first CSS framework
- **JavaScript (Vanilla/jQuery)**: Client-side interactivity
- **Chart.js**: Data visualization
- **Lucide Icons**: UI icon library

### AI/ML Components

- **StatsForecast**: Time series forecasting library
- **AutoETS**: Automatic Exponential Smoothing State Space model
- **Holt-Winters**: Exponential smoothing with trend and seasonality
- **Pandas/NumPy**: Data manipulation and processing

---

## Dashboard Features

### Admin Dashboard

The Admin Dashboard provides comprehensive oversight of all departments and system-wide drug inventory management.

#### 1. **Drug Inventory Management**

- **Add New Drugs**:

  - Drug name, category, department assignment
  - Current stock quantity
  - Batch number and expiry date

- **Edit Drug Information**:

  - Update stock quantities
  - Modify expiry dates
  - Change department assignments
  - Update categories

- **Delete Drugs**:

  - Remove drugs from inventory
  - Cascade deletion of related records

- **Search and Filter**:
  - Real-time drug search by name
  - Filter by category
  - Filter by department
  - Filter by stock status (In Stock, Low Stock, Out of Stock)

#### 2. **Dashboard Statistics Cards**

- **Total Drugs**: System-wide drug count across all departments
- **Low Stock Alerts**: Drugs with critically low inventory
- **Expiring Soon**: Drugs expiring within 30 days
- **Total Departments**: Number of active departments in the system

#### 3. **Inventory Tab**

- Comprehensive drug listing with:
  - Drug name (with "Suppository" text removal for cleaner display)
  - Category and department
  - Current stock quantity
  - Expiry date
  - Batch number
  - Status indicators (color-coded)
- Quick actions: View details, Edit, Delete
- Pagination for large datasets

#### 4. **Recent Alerts**

- Critical stock warnings
- Expiring drugs alerts
- System-wide notifications
- Real-time updates via WebSocket

#### 5. **AI Prediction / Demand Forecast**

- Individual drug demand forecasting
- 3-month ahead predictions
- Interactive charts (Chart.js)
- Historical data visualization
- Multiple prediction methods:
  - AutoETS (Automatic Exponential Smoothing)
  - Holt-Winters Exponential Smoothing
  - Trend projection
  - Mean-based fallback

#### 6. **Forecasting Tab**

- Top 50 drugs with highest predicted demand
- Cross-departmental analysis
- 3-month forecast breakdown (Month 1, Month 2, Month 3)
- Total demand calculation
- Prediction method indicators
- Department column for multi-department view
- Visual indicators for stock vs. demand comparison

#### 7. **Drug Borrowing System**

- **Create Borrowing Requests**:

  - Select source department
  - Choose drug from selected department
  - Specify quantity needed
  - Select expiry date (if available)
  - CSRF token protection

- **View All Borrowing Requests**:
  - System-wide request visibility
  - Request status tracking (Pending, Approved, Rejected, Cancelled)
  - Approve/Reject functionality
  - Cancel own requests
  - Request history with timestamps

#### 8. **Check Out Drugs**

- Log drug checkouts
- Track recipient information
- Department-specific checkout history
- Real-time inventory updates

#### 9. **Category Management**

- View all drug categories
- Filter drugs by category
- Category-based analytics

#### 10. **User Management Features**

- Department access control
- Session management
- CSRF protection
- Secure authentication

---

### Department Dashboards

Department dashboards (Surgery, Internal Medicine, Paediatrics, Gynaecology, Intensive Care Unit) provide department-specific inventory management capabilities.

#### Common Features Across All Departments

##### 1. **Department Statistics Cards**

- **Total Drugs**: Department-specific drug count
- **Critical Stock**: Drugs with low inventory or expiring soon
- **Recent Checkouts**: Last drug checkout activities
- **Demand Forecast**: Quick access to AI prediction

##### 2. **Inventory Management**

- **View Department Drugs**:

  - Drug name (cleaned display)
  - Category
  - Current stock
  - Expiry date
  - Batch number
  - Status indicators

- **Search Functionality**:

  - Real-time search by drug name
  - Filter by category
  - Live search results

- **Drug Details Modal**:
  - Comprehensive drug information
  - Current stock status
  - Expiry warnings
  - Category information

##### 3. **Recent Alerts**

- **Department-Specific Alerts**:
  - Critical stock warnings for department drugs only
  - Expiring drugs (within 30 days) specific to department
  - Real-time updates
  - Alert count display

##### 4. **Check Out Drugs Tab**

- **Drug Checkout Form**:

  - Drug selection dropdown (department drugs only)
  - Quantity input with validation
  - Recipient name input
  - Real-time stock validation

- **Recent Checkouts Display**:
  - Last 100 checkout records
  - Drug name, quantity, recipient
  - Checkout timestamp
  - Automatic refresh after checkout
  - Error handling for failed operations

##### 5. **Drug Borrowing Tab**

- **Request Drugs from Other Departments**:

  - Select "From Department" dropdown
  - Dynamic drug list population based on selected department
  - Quantity specification
  - Expiry date selection (if available)
  - Request submission with CSRF protection

- **View Borrowing Requests**:
  - Incoming requests (to this department)
  - Outgoing requests (from this department)
  - Request status tracking
  - Approve/Reject incoming requests
  - Cancel outgoing requests
  - Request details display

##### 6. **AI Prediction / Demand Forecast**

- Individual drug prediction interface
- 3-month demand forecast
- Historical data visualization
- Interactive charts
- Prediction method display
- Historical mean calculation
- Error handling for API failures

##### 7. **Forecasting Tab**

- **Department-Specific Forecast**:
  - Top drugs with highest predicted demand for the department
  - 3-month forecast breakdown
  - Current stock vs. predicted demand comparison
  - Visual indicators (red text if current stock < total demand)
  - Prediction method badges
  - Ranking system (1-50)
- **Features**:
  - Automatic loading when tab is clicked
  - Error handling and loading states
  - Responsive table design
  - Real-time data fetching from API

---

## Key Functionalities

### 1. **Stock Management**

- Real-time inventory tracking
- Automatic stock level calculation
- Low stock warnings
- Stock updates on checkout
- Cascade updates on borrowing approval

### 2. **Expiry Date Management**

- Expiry date tracking
- Automatic alerts for drugs expiring within 30 days
- Expiry date filtering
- Batch-based expiry management

### 3. **Inter-Departmental Borrowing**

- Request creation with validation
- Multi-level approval workflow
- Automatic inventory transfer on approval
- Request status tracking
- Admin oversight capability

### 4. **AI-Powered Demand Forecasting**

- **AutoETS Model**:

  - Automatic model selection
  - Seasonal and non-seasonal variants
  - Model persistence and retraining

- **Fallback Methods**:

  - Holt-Winters Exponential Smoothing
  - Trend projection
  - Linear projection
  - Growth projection
  - Mean-based forecasting

- **Data Handling**:
  - Database-driven historical data
  - Synthetic data generation for new drugs
  - Missing data interpolation
  - Trend detection and application
  - Variation injection to prevent flat forecasts

### 5. **Real-Time Updates**

- WebSocket integration for live alerts
- Automatic data refresh
- Notification system
- Status change updates

### 6. **Data Validation and Security**

- CSRF token protection
- SQL injection prevention (prepared statements)
- Input validation and sanitization
- Session management
- Department-based access control

### 7. **User Experience Features**

- Clean drug name display (removes "Suppository" text)
- Color-coded status indicators
- Responsive design
- Loading states and error messages
- Intuitive navigation

---

## Technology Stack

### Backend

- **PHP 7.4+**: Core application logic
- **MySQL/MariaDB**: Database management
- **Python 3.8+**: AI/ML services
- **FastAPI**: RESTful API for predictions
- **StatsForecast**: Time series forecasting
- **Pandas/NumPy**: Data processing
- **PyMySQL**: Database connectivity

### Frontend

- **HTML5**: Markup structure
- **Tailwind CSS**: Styling framework
- **JavaScript (ES6+)**: Client-side logic
- **jQuery**: DOM manipulation (legacy support)
- **Chart.js**: Data visualization
- **Lucide Icons**: Icon library

### Database Schema

- **drugs**: Main drug inventory table
- **drug_checkouts**: Checkout history
- **borrowing_requests**: Inter-departmental requests
- **users**: User authentication (implied)
- Foreign key relationships for data integrity

### APIs

- **FastAPI Prediction Service**:
  - Endpoint: `/predict/{drug_name}`
  - Default Port: 8000
  - Base URL: `http://127.0.0.1:8000`
  - Returns: JSON with predictions, months, method, historical data
  - Features: Drug name normalization, error handling, suggestions
  - Startup Command: `uvicorn app:app --host 0.0.0.0 --port 8000`

---

## Recommendations for Improvement

### 1. **Performance Optimization**

#### Database Optimization

- **Implement Indexing**:
  - Add indexes on frequently queried columns (`drug_name`, `department`, `expiry_date`, `checkout_time`)
  - Composite indexes for common query patterns
  - Full-text search index for drug name searches
- **Query Optimization**:

  - Implement pagination for large result sets
  - Use LIMIT clauses more effectively
  - Consider materialized views for complex aggregations
  - Implement query result caching for static data

- **Connection Pooling**:
  - Implement database connection pooling
  - Reduce connection overhead
  - Better resource management

#### Frontend Optimization

- **Lazy Loading**:
  - Implement lazy loading for dashboard tabs
  - Load data on-demand rather than all at once
  - Progressive image loading
- **Caching Strategy**:
  - Implement browser caching for static assets
  - Use localStorage for frequently accessed data
  - Cache API responses with appropriate expiration
- **Code Splitting**:
  - Separate JavaScript bundles by dashboard
  - Reduce initial page load time
  - Load scripts asynchronously

### 2. **Enhanced AI/ML Features**

#### Model Improvements

- **Multiple Model Ensemble**:
  - Combine predictions from AutoETS, ARIMA, Prophet, and LSTM
  - Weighted averaging based on historical accuracy
  - Model selection based on data characteristics
- **Feature Engineering**:
  - Include external factors (seasonality, holidays, disease outbreaks)
  - Historical trend analysis
  - Category-based demand patterns
  - Department-specific usage patterns
- **Model Evaluation**:
  - Implement backtesting framework
  - Calculate prediction accuracy metrics (MAE, RMSE, MAPE)
  - Model performance tracking dashboard
  - A/B testing for different models

#### Prediction Enhancements

- **Confidence Intervals**:
  - Add upper and lower bounds for predictions
  - Uncertainty quantification
  - Risk assessment visualization
- **Anomaly Detection**:
  - Identify unusual demand patterns
  - Alert on unexpected spikes or drops
  - Automatic flagging of outliers
- **Scenario Planning**:
  - "What-if" analysis for different scenarios
  - Best-case and worst-case projections
  - Impact of external events

### 3. **User Interface/Experience Improvements**

#### Dashboard Enhancements

- **Interactive Dashboards**:
  - Draggable widgets
  - Customizable dashboard layouts
  - Save user preferences
- **Advanced Filtering**:
  - Multi-select filters
  - Date range pickers
  - Advanced search with multiple criteria
  - Saved filter presets
- **Visualizations**:
  - Interactive charts with zoom and pan
  - Trend lines and annotations
  - Comparative charts (multiple drugs)
  - Heat maps for inventory levels
  - Geographic distribution (if multi-location)

#### Mobile Responsiveness

- **Progressive Web App (PWA)**:
  - Offline functionality
  - Push notifications
  - App-like experience
- **Mobile-Optimized Views**:
  - Touch-friendly interfaces
  - Simplified navigation for mobile
  - Responsive tables with horizontal scroll
  - Mobile-specific features

### 4. **Security Enhancements**

#### Authentication & Authorization

- **Multi-Factor Authentication (MFA)**:
  - SMS or email-based OTP
  - Authenticator app support
  - Biometric authentication for mobile
- **Role-Based Access Control (RBAC)**:
  - Granular permissions system
  - Role hierarchy
  - Department-specific role assignments
  - Audit logs for permission changes

#### Data Security

- **Encryption**:
  - Encrypt sensitive data at rest
  - TLS 1.3 for data in transit
  - Field-level encryption for PII
- **Security Monitoring**:
  - Real-time threat detection
  - Failed login attempt tracking
  - Suspicious activity alerts
  - Regular security audits

### 5. **Reporting and Analytics**

#### Advanced Reporting

- **Custom Report Builder**:
  - Drag-and-drop report designer
  - Multiple data sources
  - Scheduled report generation
  - Export to PDF, Excel, CSV
- **Analytics Dashboard**:
  - Usage statistics
  - Department performance metrics
  - Drug utilization analysis
  - Cost analysis and optimization
  - Trend analysis over time

#### Business Intelligence

- **Data Warehouse**:
  - Historical data aggregation
  - ETL processes for data transformation
  - Separate reporting database
- **KPI Tracking**:
  - Inventory turnover rate
  - Stockout frequency
  - Average order fulfillment time
  - Department efficiency metrics

### 6. **Integration Capabilities**

#### Third-Party Integrations

- **Hospital Information System (HIS) Integration**:
  - Automatic patient data sync
  - Prescription-based demand prediction
  - Automated reorder triggers
- **Vendor Management System**:
  - Automatic purchase order generation
  - Vendor performance tracking
  - Price comparison tools
- **Barcode/RFID Integration**:
  - Scan-based checkout
  - Automatic inventory updates
  - Batch tracking
  - Reduced manual entry errors

#### API Development

- **RESTful API**:
  - Standardized API endpoints
  - API versioning
  - Comprehensive API documentation
  - Rate limiting and throttling
- **Webhook Support**:
  - Real-time event notifications
  - Integration with external systems
  - Automated workflows

### 7. **Workflow Improvements**

#### Automated Workflows

- **Auto-Reordering**:
  - Automatic purchase order generation based on forecasts
  - Threshold-based reordering
  - Vendor selection automation
- **Approval Workflows**:
  - Multi-level approval chains
  - Parallel approval options
  - Approval delegation
  - Time-based escalations

#### Notification System

- **Multi-Channel Notifications**:
  - Email notifications
  - SMS alerts for critical events
  - In-app notifications
  - Push notifications (PWA)
- **Smart Notifications**:
  - Priority-based filtering
  - User preference management
  - Notification grouping
  - Do-not-disturb modes

### 8. **Data Quality and Management**

#### Data Validation

- **Enhanced Validation Rules**:
  - Drug name standardization
  - Duplicate detection and prevention
  - Data quality scoring
  - Automatic data cleansing
- **Master Data Management**:
  - Centralized drug master list
  - Synonym management
  - Standardized naming conventions
  - Drug categorization improvements

#### Backup and Recovery

- **Automated Backups**:
  - Daily incremental backups
  - Weekly full backups
  - Off-site backup storage
  - Backup verification processes
- **Disaster Recovery**:
  - Point-in-time recovery
  - Failover systems
  - Business continuity planning
  - Regular DR drills

### 9. **Compliance and Audit**

#### Regulatory Compliance

- **FDA/Regulatory Compliance**:
  - Drug tracking and traceability
  - Batch recall management
  - Regulatory reporting
- **Audit Trails**:
  - Comprehensive activity logging
  - User action tracking
  - Data change history
  - Immutable audit logs

#### Documentation

- **User Documentation**:
  - Interactive tutorials
  - Video guides
  - Contextual help tooltips
  - FAQ section
- **Technical Documentation**:
  - API documentation
  - Database schema documentation
  - Deployment guides
  - Troubleshooting guides

### 10. **Scalability Considerations**

#### Infrastructure

- **Cloud Migration**:
  - Move to cloud infrastructure (AWS, Azure, GCP)
  - Auto-scaling capabilities
  - Load balancing
  - CDN for static assets
- **Microservices Architecture**:
  - Break monolithic application into microservices
  - Independent scaling
  - Service mesh implementation
  - API gateway

#### Database Scaling

- **Read Replicas**:
  - Separate read and write databases
  - Reduce load on primary database
  - Geographic distribution
- **Sharding**:
  - Horizontal database sharding
  - Department-based partitioning
  - Improved query performance

### 11. **Testing and Quality Assurance**

#### Automated Testing

- **Unit Testing**:
  - PHPUnit for PHP code
  - Pytest for Python code
  - High code coverage (>80%)
- **Integration Testing**:
  - API endpoint testing
  - Database integration tests
  - Third-party service mocking
- **End-to-End Testing**:
  - Selenium/Cypress for UI testing
  - User journey testing
  - Cross-browser testing

#### Quality Metrics

- **Code Quality**:
  - Static code analysis
  - Code review processes
  - Coding standards enforcement
- **Performance Testing**:
  - Load testing
  - Stress testing
  - Performance benchmarking

### 12. **User Training and Adoption**

#### Training Programs

- **Onboarding**:
  - Interactive tutorials for new users
  - Role-specific training paths
  - Certification programs
- **Ongoing Education**:
  - Feature update announcements
  - Best practices sharing
  - User community forums

#### Change Management

- **Feature Rollout**:
  - Gradual feature releases
  - User feedback collection
  - A/B testing for new features
  - Rollback capabilities

---

## Implementation Priority

### Phase 1 (Immediate - 1-3 months)

1. Database indexing and query optimization
2. Enhanced error handling and logging
3. Mobile responsiveness improvements
4. Security enhancements (CSRF, XSS protection)
5. Basic analytics dashboard

### Phase 2 (Short-term - 3-6 months)

1. Advanced AI/ML model improvements
2. Automated reordering system
3. Enhanced reporting capabilities
4. API development and documentation
5. Barcode/RFID integration

### Phase 3 (Medium-term - 6-12 months)

1. Cloud migration
2. Microservices architecture
3. Advanced analytics and BI
4. HIS integration
5. Mobile app development

### Phase 4 (Long-term - 12+ months)

1. Multi-location support
2. Advanced compliance features
3. Machine learning model ensemble
4. Predictive maintenance
5. Advanced workflow automation

---

## Conclusion

The Drug Inventory Management System provides a solid foundation for managing pharmaceutical inventory across multiple departments. The system successfully integrates AI-powered forecasting, real-time monitoring, and inter-departmental collaboration features.

By implementing the recommended improvements, the system can evolve into a comprehensive, enterprise-grade solution that scales with organizational needs, provides deeper insights, and enhances operational efficiency while maintaining security and compliance standards.

---

**Created**: 16th November 2025  
**Author**: Akampondi Edinah
