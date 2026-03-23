# Hall Management System - Administrator Manual
## For System Administrators

### Table of Contents
1. [System Overview](#system-overview)
2. [Admin Dashboard](#admin-dashboard)
3. [Reservation Management](#reservation-management)
4. [Hall & Building Management](#hall--building-management)
5. [User Management](#user-management)
6. [Fixed Reservations](#fixed-reservations)
7. [Timetable Management](#timetable-management)
8. [Reports & Analytics](#reports--analytics)
9. [System Configuration](#system-configuration)
10. [Troubleshooting](#troubleshooting)

---

## System Overview

The Hall Management System Administrator Panel provides comprehensive control over:
- Reservation approval workflows
- Hall and building management
- User account management
- System configuration and reporting
- Fixed reservation scheduling
- Timetable integration

**Admin Features:**
- Multi-level approval system
- Real-time availability monitoring
- Google Calendar integration
- Payment verification
- Comprehensive reporting tools

---

## Getting Started

### Admin Access
1. Navigate to the admin login URL
2. Enter admin credentials
3. Click "Sign In"

### Security Requirements
- Use secure, unique passwords
- Enable two-factor authentication if available
- Log out after each session
- Never share admin credentials

---

## Admin Dashboard

### Main Navigation
The admin dashboard provides quick access to:

#### Primary Functions
- **Pending Requests**: View and approve reservation requests
- **Approved History**: Review all approved reservations
- **Generate Reports**: Create system reports
- **View Free Slots**: Check hall availability

#### Management Functions
- **Fixed Reservations ▾**
  - Upload Timetable: Import academic timetables
  - Fixed Reservations: Manage recurring bookings
- **More ▾**
  - Create User Accounts: Manage user access
  - Change Dean's Email: Update approval contact
  - Manage Halls & Prices: Configure halls and pricing
  - Sign Out: Secure logout

### Admin Dashboard Overview

#### Main Content Area
The admin dashboard displays the **Admin Approval Portal** which shows:
- **Title**: "Admin Approval Portal"
- **Description**: "Reservations that have been approved by HOD and Dean. Review and approve or reject below."

#### Pending Requests Display
- **Empty State**: "No pending requests at the moment" (if no pending approvals)
- **Pending List**: Each request shows:
  - Request #ID and Dean approval date
  - Applicant name and department
  - Building and Hall details
  - Start/End datetime
  - Purpose of reservation
  - Action buttons: "View Details & Approve" and "Reject"

#### Navigation Menu
See Main Navigation section below for all available functions.

---

## Reservation Management

### Pending Requests Workflow

#### Review Process
1. **Access Pending Requests** from main menu
2. **Review Request Details**:
   - Applicant information
   - Reservation purpose
   - Hall and time details
   - Payment slip verification
   - Google Calendar conflicts

#### Approval Actions
- **Approve**: Move request to next approval level
- **Reject**: Deny with reason
- **Request Changes**: Ask for additional information

#### Payment Verification
- **Check Payment Slip**: Verify payment authenticity
- **Validate Amount**: Confirm correct payment for hall type
- **Cross-Reference**: Match with financial records if needed

### Approved History Management

#### Viewing Approved Reservations
- **Search by Date**: Filter by specific date ranges
- **Filter by Status**: View by approval stage
- **Export Data**: Download reservation lists

#### Managing Approved Requests
- **View Details**: Complete reservation information
- **Cancel Reservations**: Admin-level cancellation authority
- **Modify Details**: Edit reservation information (if necessary)
- **Generate Receipts**: Create official reservation confirmations

### Approval Workflow Management

#### Multi-Level System
1. **HOD/Department Head**: Initial approval
2. **Dean**: Faculty-level approval
3. **Administrator**: Final confirmation

#### Workflow Controls
- **Override Authority**: Bypass normal workflow for emergencies
- **Delegate Authority**: Assign temporary approval rights
- **Batch Processing**: Approve multiple requests simultaneously

---

## Hall & Building Management

### Manage Halls & Prices

#### Building Management
1. **Add Building**:
   - Enter building name (e.g., "A11", "Science Faculty")
   - Click "Add Building"

2. **Building List**:
   - View all existing buildings
   - Building names are read-only after creation

#### Hall Management
1. **Add Hall**:
   - **Hall Name**: Room number (e.g., "301", "LR-01")
   - **Building**: Select from dropdown
   - **Capacity**: Maximum occupancy
   - **Hall Type**: Lecture Hall, Laboratory, etc.
   - **Optional Pricing**: Add refundable/non-refundable amounts

2. **Edit Existing Halls**:
   - **Capacity**: Modify maximum occupancy
   - **Type**: Change hall classification
   - **Building/Hall Name**: Read-only (for data integrity)

#### Price Management
1. **Set Hall Prices**:
   - **Refundable Amount**: Security deposit
   - **Non-Refundable**: Booking fee
   - **Optional**: Prices not required for all halls

2. **Update Prices**:
   - Inline editing in price table
   - Real-time price updates
   - Historical price tracking

### Hall Configuration Guidelines

#### Capacity Planning
- **Lecture Halls**: 50-200 students
- **Laboratories**: 20-40 students
- **Meeting Rooms**: 10-30 students
- **Auditoriums**: 100+ students

#### Pricing Strategy
- **Premium Halls**: Higher rates for specialized facilities
- **Peak Hours**: Different rates for prime time
- **Student Discounts**: Reduced rates for academic use

---

## User Management

### Create User Accounts

#### User Types
- **Students**: Standard reservation access
- **Faculty**: Extended booking privileges
- **Staff**: Administrative access levels
- **Department Heads**: Approval authority

#### Account Creation Process
1. **Access User Management** from "More ▾" menu
2. **Enter User Details**:
   - Full name
   - Email address
   - User role/level
   - Department/association
   - Contact information

3. **Set Initial Password**:
   - Temporary password generation
   - Password reset requirements
   - Account activation

#### User Account Management
- **Edit Profiles**: Update user information
- **Reset Passwords**: Handle forgotten passwords
- **Deactivate Accounts**: Remove access for departed users
- **Permission Levels**: Adjust user access rights

### User Support

#### Common User Issues
- **Login Problems**: Password resets, account locks
- **Reservation Difficulties**: Form completion, slot selection
- **Payment Questions**: Fee structures, slip uploads
- **Approval Delays**: Workflow status, contact information

#### Support Procedures
- **Ticket System**: Track user support requests
- **Knowledge Base**: Maintain FAQ documentation
- **Training Materials**: Provide user guides
- **Communication Channels**: Email, phone, in-person support

---

## Fixed Reservations

### Upload Timetable

#### Academic Timetable Integration
1. **Prepare Timetable File**:
   - CSV or Excel format
   - Required columns: Subject, Hall, Day, Time, Duration
   - Academic calendar alignment

2. **Upload Process**:
   - Select timetable file
   - Map column headers
   - Preview import data
   - Confirm upload

3. **Validation**:
   - Hall availability checking
   - Time conflict resolution
   - Duplicate detection

#### Timetable Management
- **Regular Updates**: Semester changes, course modifications
- **Conflict Resolution**: Handle scheduling overlaps
- **Archive Management**: Store historical timetables

### Fixed Reservations Management

#### Recurring Bookings
1. **Create Fixed Reservations**:
   - Regular classes (weekly)
   - Semester-long bookings
   - Department meetings
   - Special events

2. **Reservation Parameters**:
   - Hall selection
   - Recurring pattern (daily, weekly, monthly)
   - Time slots
   - Duration (end date)
   - Purpose/justification

#### Fixed Reservation Features
- **Priority Booking**: Fixed reservations override regular requests
- **Conflict Prevention**: Block conflicting time slots
- **Automatic Updates**: Sync with academic calendar
- **Exception Handling**: One-time cancellations or modifications

---

## Timetable Management

### Academic Calendar Integration

#### Semester Management
- **Fall Semester**: August - December
- **Spring Semester**: January - May
- **Summer Session**: June - July
- **Exam Periods**: Special scheduling rules

#### Calendar Events
- **Public Holidays**: Automatic blocking
- **Exam Periods**: Restricted access
- **Registration Periods**: Special booking rules
- **Maintenance Windows**: System downtime scheduling

### Timetable Optimization

#### Utilization Analysis
- **Hall Usage Reports**: Peak hours, underutilized spaces
- **Capacity Planning**: Demand forecasting
- **Scheduling Efficiency**: Optimal time allocation
- **Resource Allocation**: Equipment and facility management

#### Conflict Resolution
- **Double Booking Prevention**: Automated conflict detection
- **Priority Scheduling**: Academic vs. event bookings
- **Emergency Rescheduling**: Crisis management protocols

---

## Reports & Analytics

### Generate Reports

#### Available Report Types
1. **Reservation Reports**:
   - Daily/weekly/monthly summaries
   - Hall utilization statistics
   - User booking patterns
   - Revenue tracking (if applicable)

2. **User Activity Reports**:
   - Registration statistics
   - Booking frequency analysis
   - Department usage patterns
   - Peak demand periods

3. **System Performance Reports**:
   - Response time metrics
   - Error rate tracking
   - User satisfaction scores
   - System uptime statistics

#### Report Customization
- **Date Ranges**: Flexible time period selection
- **Filter Options**: By hall, user, department, status
- **Export Formats**: PDF, Excel, CSV
- **Scheduled Reports**: Automated email delivery

### Analytics Dashboard

#### Key Performance Indicators
- **Reservation Volume**: Total bookings over time
- **Approval Rates**: Percentage of approved requests
- **Hall Utilization**: Space usage efficiency
- **User Engagement**: Active user statistics

#### Data Visualization
- **Trend Charts**: Historical booking patterns
- **Heat Maps**: Peak usage visualization
- **Comparative Analysis**: Year-over-year comparisons
- **Forecasting Models**: Demand prediction

---

## System Configuration

### Dean's Email Management

#### Update Approval Contact
1. **Access "Change Dean's Email"** from "More ▾" menu
2. **Enter New Email**: Current dean's email address
3. **Confirm Update**: Verify email accuracy
4. **Test Notification**: Send test email to confirm

#### Email Configuration
- **SMTP Settings**: Outgoing mail server configuration
- **Email Templates**: Customize notification messages
- **Delivery Monitoring**: Track email delivery success
- **Bounce Handling**: Manage failed email deliveries

### System Settings

#### Time Configuration
- **Operating Hours**: 8:00 AM - 10:00 PM default
- **Booking Window**: Advance reservation limits
- **Cancellation Policy**: Time restrictions for cancellations
- **Approval Timeouts**: Automatic escalation rules

#### Security Settings
- **Session Management**: Timeout configurations
- **Access Controls**: IP restrictions, rate limiting
- **Audit Logging**: Track administrative actions
- **Backup Procedures**: Data protection protocols

---

## Troubleshooting

### Common System Issues

#### Database Problems
- **Connection Failures**: Check database server status
- **Slow Performance**: Optimize queries, check indexes
- **Data Corruption**: Restore from backups
- **Lock Issues**: Resolve deadlocks, timeout problems

#### User Interface Issues
- **Page Loading Errors**: Check server status, clear cache
- **Form Submission Failures**: Validate data, check permissions
- **Display Problems**: Test browser compatibility
- **Mobile Access**: Verify responsive design

#### Integration Issues
- **Google Calendar Sync**: API authentication, quota limits
- **Email Delivery**: SMTP configuration, spam filters
- **File Uploads**: Storage space, file type restrictions
- **Payment Processing**: Gateway connectivity, transaction logs

### Emergency Procedures

#### System Downtime
1. **Assessment**: Determine impact and scope
2. **Communication**: Notify users of outage
3. **Recovery**: Restore from backups, restart services
4. **Verification**: Test system functionality
5. **Post-Mortem**: Document and analyze incident

#### Data Recovery
- **Backup Restoration**: Point-in-time recovery options
- **Data Validation**: Verify data integrity
- **User Notification**: Inform affected users
- **Prevention Measures**: Implement safeguards

### Performance Optimization

#### Database Optimization
- **Query Analysis**: Identify slow queries
- **Index Management**: Optimize database indexes
- **Connection Pooling**: Manage database connections
- **Caching Strategies**: Implement query caching

#### Server Performance
- **Load Balancing**: Distribute server load
- **Resource Monitoring**: CPU, memory, disk usage
- **Scaling Planning**: Capacity expansion strategies
- **Security Audits**: Regular vulnerability assessments

---

## Best Practices

### Administrative Procedures

#### Daily Tasks
- **Review Pending Requests**: Process within 24 hours
- **Check System Status**: Monitor for errors or issues
- **User Support**: Address user inquiries promptly
- **Backup Verification**: Confirm backup completion

#### Weekly Tasks
- **Generate Reports**: Review system usage statistics
- **User Account Review**: Remove inactive accounts
- **Security Audit**: Check for suspicious activity
- **Performance Monitoring**: Analyze system metrics

#### Monthly Tasks
- **System Maintenance**: Apply updates and patches
- **Data Cleanup**: Archive old records
- **Training Updates**: Refresh user documentation
- **Capacity Planning**: Review resource utilization

### Security Best Practices

#### Access Control
- **Principle of Least Privilege**: Minimum necessary access
- **Regular Password Changes**: Enforce password policies
- **Session Management**: Secure logout procedures
- **Audit Trails**: Log all administrative actions

#### Data Protection
- **Regular Backups**: Automated backup schedules
- **Encryption**: Protect sensitive data
- **Access Logs**: Monitor system access
- **Compliance**: Adhere to data protection regulations

---

## Contact Information

### Technical Support
- **System Administrator**: [email protected]
- **Database Administrator**: [email protected]
- **Network Support**: [email protected]
- **Security Team**: [email protected]

### Administrative Support
- **Hall Management Office**: [email protected]
- **Dean's Office**: [email protected]
- **IT Helpdesk**: [email protected]
- **Finance Office**: [email protected]

### Emergency Contacts
- **Critical System Issues**: +94 77 123 4567
- **Data Center Emergency**: +94 77 234 5678
- **Security Incident**: +94 77 345 6789

---

## Training & Documentation

### Admin Training Programs
- **Basic Administration**: Essential system management
- **Advanced Features**: Complex configuration options
- **Security Procedures**: Security best practices
- **Emergency Response**: Crisis management protocols

### Documentation Maintenance
- **User Manuals**: Regular updates for new features
- **API Documentation**: Technical integration guides
- **Procedural Guides**: Step-by-step instructions
- **Knowledge Base**: FAQ and troubleshooting articles

---

## System Requirements

### Server Requirements
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **PHP**: Version 7.4+ with required extensions
- **Database**: MySQL 5.7+ or MariaDB 10.3+
- **Storage**: Minimum 50GB available space

### Browser Requirements
- **Chrome**: Version 90+ (recommended)
- **Firefox**: Version 88+
- **Safari**: Version 14+
- **Edge**: Version 90+

### Network Requirements
- **Bandwidth**: 10 Mbps+ for optimal performance
- **Latency**: <100ms for database connections
- **Uptime**: 99.5% availability target
- **Security**: HTTPS required for all connections

---

*This manual is updated regularly. For the latest version and additional resources, visit the admin help section or contact the system administrator.*
