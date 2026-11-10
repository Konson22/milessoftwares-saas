# TASK: RESTRUCTURE THE EXISTING APPLICATION INTO A FULLY STANDALONE NGO, DONOR, AND BENEFICIARY PROJECT MANAGEMENT PLATFORM

## 1. YOUR ROLE

Act as a principal software architect, senior full-stack engineer, database architect, security engineer, and technical lead.

Your responsibility is to inspect, evaluate, refactor, and progressively transform the existing application into a fully independent project and programme management platform designed for:

- Non-Governmental Organizations (NGOs)
- International NGOs
- Donor Organizations
- Development Partners
- Implementing Partners
- Government and Public Institutions
- Field Officers
- Project Managers
- Finance and Procurement Teams
- Monitoring and Evaluation Teams
- Beneficiaries

The application must combine comprehensive ClickUp-inspired project and work management features with NGO-specific functionality.

These include:

- Programme and Project Management
- Task and Workplan Management
- Grant and Donor Management
- Financial and Budget Management
- Budget Allocation
- Procurement Management
- Beneficiary Management
- Monitoring, Evaluation, Accountability and Learning (MEAL)
- Data Collection
- Donor Reporting
- Partner Management
- Resource Management
- AI Assistance
- External Application Integrations

The most important architectural requirement is to make the existing application completely standalone.

It must no longer depend on the existing external SSO system, central authentication service, or shared central application database.

**Do not rebuild the application from scratch unless the existing architecture is demonstrably unsuitable and there is no reasonable incremental migration path.**

Preserve working functionality and existing business data.

---

# 2. MANDATORY FIRST STEP: INSPECT THE EXISTING APPLICATION

Before modifying any code, inspect the entire application.

Investigate:

### Frontend

- Current frontend framework
- Routing structure
- Existing pages
- UI components
- Authentication flow
- API clients
- State management
- Existing layouts
- Navigation structure
- Role-based UI restrictions

### Backend

- Backend framework
- Application architecture
- Authentication middleware
- Authorization guards
- API routes
- Service classes
- Business logic
- Background jobs
- Integration services

### Database

- Current database engine
- Database connection configuration
- ORM and migrations
- Existing schema
- User tables
- Organization tables
- Workspace tables
- Project tables
- Task tables
- Financial tables
- Relationships and foreign keys

### Current SSO Integration

Identify every dependency on the existing SSO infrastructure.

Inspect:

- Authentication redirects
- OAuth/OIDC configuration
- JWT validation
- SSO middleware
- External user identifiers
- Token refresh mechanisms
- Session validation
- SSO callback routes
- Logout callbacks
- External identity providers
- Central user profile synchronization
- Role and permission synchronization

### Central Database Dependencies

Identify every dependency on the current central database, including:

- Shared user tables
- Shared organizations
- Shared permissions
- Central database connections
- Cross-database queries
- Foreign database references
- Shared schemas
- Centralized user provisioning
- Shared background processes

Create a dependency inventory showing which modules rely on the external SSO or central database.

Do not begin removing dependencies until their replacements and migration strategies have been identified.

---

# 3. PRIMARY OBJECTIVE: COMPLETE APPLICATION INDEPENDENCE

The application must operate independently.

After restructuring, it must have:

1. Its own authentication system.
2. Its own user accounts.
3. Its own organization management.
4. Its own role-based access control.
5. Its own database.
6. Its own session management.
7. Its own permission system.
8. Its own application configuration.
9. Its own file storage configuration.
10. Its own administrative interface.
11. Its own API services.
12. Its own deployment configuration.
13. Its own backup and recovery procedures.

The application must not require:

- External SSO for user authentication.
- A central user management API.
- A shared authentication database.
- A shared platform database.
- A central organization management service.
- Shared authentication tokens issued by the former platform.
- Another application to create or approve users.

External integrations such as Microsoft 365, Google Workspace, OpenAI, or KoboToolbox may remain optional.

They must not be required for basic authentication or core application functionality.

## Critical Requirement

Do not confuse application independence with single-user or single-organization operation.

The application must remain capable of serving multiple independent organizations through its own internal multi-tenant architecture.

---

# 4. REPLACE SSO WITH LOCAL AUTHENTICATION

Implement independent application authentication.

Required functionality:

### Authentication

- User registration
- User login
- User logout
- Email verification
- Forgot password
- Reset password
- Change password
- Secure session management
- Session expiration
- Account activation and deactivation
- Login rate limiting

### User Management

- Create users
- Invite users
- Edit profiles
- Assign organization memberships
- Assign roles
- Suspend accounts
- Manage invitations
- View user activity

### Security

Use:

- Argon2id password hashing
- Secure HTTP-only cookies
- Secure session storage
- CSRF protection where applicable
- Authentication rate limiting
- Account recovery tokens with expiration
- Server-side permission enforcement
- Audit logging

Do not store passwords or authentication secrets in plaintext.

Do not store long-lived authentication tokens in localStorage.

Use an established, well-maintained authentication library compatible with the existing backend.

Do not create a custom cryptographic authentication protocol.

## Migration Requirement

Existing SSO users must not silently lose access.

Determine how existing identities can be mapped to local accounts.

If passwords cannot be migrated securely, establish a verified account activation or password-setting process.

Do not assume external SSO password hashes are available or transferable.

Do not automatically match privileged accounts using unverified email addresses.

Preserve old identity identifiers in a restricted migration mapping until migration verification is complete.

---

# 5. REMOVE CENTRAL DATABASE DEPENDENCIES

The application must use its own dedicated database.

Preferred database:

PostgreSQL.

However, if the current application uses a suitable database engine, evaluate whether retaining it is safer than replacing it.

## Required Database Migration Process

1. Inspect the current schema.
2. Identify application-owned tables.
3. Identify centrally managed tables.
4. Identify cross-database dependencies.
5. Determine data ownership.
6. Create a standalone schema.
7. Map existing data to the new schema.
8. Create version-controlled migrations.
9. Prepare migration scripts.
10. Test migration using a database copy.
11. Validate record counts and relationships.
12. Test authentication and authorization.
13. Prepare rollback procedures.
14. Plan the production cutover.

Do not delete the central database.

Do not modify unrelated applications that still depend on it.

Only remove this application's dependency after the standalone environment is operational and verified.

## Data Preservation

Preserve existing:

- Users
- Organizations
- Projects
- Tasks
- Assignments
- Comments
- Attachments
- Financial records
- Reports
- Activity history
- Permissions
- Relevant configuration

Preserve original identifiers through migration mapping where necessary.

Do not fabricate missing data.

Record unmigrated or conflicting records for manual review.

## No Shared Database Dependencies

All core application reads and writes must use the standalone database.

No runtime queries should depend on the former central database.

External systems may be connected through explicit, authorized API integrations.

---

# 6. MULTI-ORGANIZATION AND MULTI-TENANT ARCHITECTURE

The standalone application must support multiple organizations.

Examples:

- NGO A
- NGO B
- International NGO C
- Donor Organization D
- Implementing Partner E

Each organization should have independent:

- Members
- Teams
- Departments
- Projects
- Workspaces
- Budgets
- Financial records
- Documents
- Configuration
- Permissions

Users may belong to multiple organizations.

Organizations must be able to collaborate on authorized projects.

A donor may fund projects implemented by several NGOs.

An NGO may receive funding from multiple donors.

Do not implement a globally exclusive organization type.

Instead, support organization capabilities and project-specific relationships.

For example:

Organization A may act as a donor in one project and an implementing partner in another.

## Tenant Security

Every organization-owned record must have an appropriate tenant relationship.

All queries must enforce tenant isolation.

Implement:

- Workspace membership validation
- Organization membership validation
- Project-level access grants
- Resource-level permissions
- Donor-specific visibility
- Cross-organization collaboration permissions

Do not rely on frontend restrictions to protect tenant data.

Test cross-tenant access attempts.

---

# 7. APPLICATION ROLES AND ACCESS CONTROL

Implement a configurable permission system.

Suggested platform roles:

- Platform Administrator
- Organization Administrator
- Organization Member

Suggested project roles:

- Project Director
- Project Manager
- Programme Manager
- Finance Manager
- Finance Officer
- Procurement Officer
- M&E Officer
- Field Officer
- Implementing Partner
- Donor Representative
- Project Viewer

Beneficiary portal permissions must be managed separately.

Do not create an excessive number of hardcoded global roles.

Use permissions and role assignments scoped to the appropriate organization, programme, project, or funding agreement.

## Donor Permissions

Donors should be able to:

- Access authorized funded projects.
- View approved budget information.
- View permitted expenditure reports.
- Monitor implementation progress.
- Access submitted donor reports.
- Review indicators and results.
- Request report revisions.
- Communicate with implementing partners.

Donors must not automatically access:

- Internal NGO administration.
- Confidential staff records.
- Unrelated projects.
- Other donors' restricted information.
- Private beneficiary records.
- Internal financial approvals.

---

# 8. CORE CLICKUP-INSPIRED WORK MANAGEMENT

Retain existing project management functionality and progressively expand it.

The target system should support the following:

## Workspace Management

- Workspaces
- Spaces
- Folders
- Lists
- Workspace members
- Workspace settings

## Project Management

- Programmes
- Projects
- Project managers
- Project teams
- Project lifecycle stages
- Project milestones
- Project progress
- Project templates

## Task Management

- Tasks
- Subtasks
- Checklists
- Multiple assignees
- Priorities
- Custom statuses
- Due dates
- Start dates
- Recurring tasks
- Task dependencies
- Attachments
- Comments
- Mentions
- Activity history

## Views

- List View
- Board View
- Calendar View
- Gantt View
- Timeline View
- Table View
- Workload View
- Activity View

## Collaboration

- Task comments
- Notifications
- Team discussions
- Documents
- Wikis
- Shared workspaces

## Productivity

- Time tracking
- Timesheets
- Goals
- OKRs
- Sprint management
- Automation
- Custom fields
- Dashboards
- Templates
- Global search

Implement the complete functionality progressively.

Do not create placeholder pages merely to make the application appear feature-complete.

---

# 9. FINANCIAL AND BUDGET MANAGEMENT

Financial management is a core requirement.

The application must support:

### Project Budgeting

- Project budgets
- Budget categories
- Budget line items
- Budget preparation
- Budget submission
- Budget approval
- Budget revisions
- Budget version history

### Budget Allocation

- Allocations by project
- Allocations by activity
- Allocations by department
- Allocations by funding source
- Budget transfers
- Available balance calculation

### Financial Management

- Expense requests
- Expense approvals
- Commitments
- Expenditure tracking
- Payment status
- Supporting documents
- Budget versus actual
- Financial reports

### Donor Funding

- Funding agreements
- Grant allocations
- Restricted funding
- Donor disbursements
- Grant balances
- Funding utilization

## Financial Integrity

Approved budgets must be versioned.

Do not overwrite approved financial records.

Financial corrections must be traceable.

Implement appropriate transaction boundaries.

Enforce budget availability checks.

Prevent double-counting of committed and expended amounts.

Support multi-currency records with explicit exchange-rate information.

Do not introduce full general ledger accounting unless it already exists or is separately approved.

---

# 10. PROCUREMENT MANAGEMENT

Implement a dedicated procurement domain.

Features:

- Procurement plans
- Purchase requisitions
- Specifications and Terms of Reference
- Procurement approval workflows
- RFQs and tender management
- Supplier management
- Bid evaluation
- Purchase orders
- Contracts
- Goods receipt
- Invoice verification
- Payment tracking
- Procurement reporting

Procurement requests should reference approved project budgets.

Approved purchase orders should create or update financial commitments when applicable.

Avoid treating procurement approval as payment completion.

---

# 11. GRANT AND DONOR MANAGEMENT

Implement:

- Donor organizations
- Grant opportunities
- Grant applications
- Grant agreements
- Funding allocations
- Disbursement schedules
- Grant reporting requirements
- Grant amendments
- Grant closeout
- Subgrants
- Partner funding
- Donor reporting

Support multiple donors per project and multiple projects per funding agreement.

Avoid duplicating entire projects for different donors.

---

# 12. BENEFICIARY MANAGEMENT

Develop a secure beneficiary management module.

Features:

- Beneficiary registration
- Beneficiary profiles
- Programme participation
- Eligibility records
- Service requests
- Assistance tracking
- Feedback
- Complaints
- Authorized beneficiary access

Beneficiary information must be protected.

Do not automatically expose personally identifiable information to donors.

Implement data minimization, appropriate consent or lawful processing controls, retention policies, and restricted access for sensitive cases.

Beneficiary portal identities should be separate from internal staff permission models.

---

# 13. MONITORING, EVALUATION, ACCOUNTABILITY AND LEARNING

Implement MEAL functionality.

Features:

- Results frameworks
- Logical frameworks
- Goals
- Outcomes
- Outputs
- Indicators
- Baselines
- Targets
- Actual achievements
- Means of verification
- Evaluation reports
- Community feedback
- Lessons learned

Connect activities and tasks to measurable project results.

Support reporting by programme, project, indicator, donor, and implementation period.

---

# 14. DATA COLLECTION AND EXTERNAL APPLICATIONS

Create an App Center that supports optional external integrations.

Priority integrations:

- KoboToolbox
- ODK Central
- OpenAI
- Microsoft 365
- Google Workspace

The application must operate even when these services are unavailable.

## KoboToolbox

Support:

- Connecting an account
- Listing accessible forms
- Linking forms to projects
- Retrieving submissions
- Synchronizing submission metadata
- Viewing collection statistics
- Associating data with indicators

## AI Assistant

Use the OpenAI API through the backend.

Support:

- General AI assistance
- Task writing
- Project summaries
- Report drafting
- Financial analysis
- Monitoring and evaluation analysis

AI must only access authorized application data.

Never allow AI to approve expenditure or change financial records without explicit authorization and confirmation.

## Integration Security

Store external credentials securely.

Use OAuth where available.

Encrypt API credentials.

Maintain connection-level access control.

Implement retry handling, synchronization logs, and rate-limit protection.

---

# 15. APPLICATION NAVIGATION

Restructure the current interface into a unified application.

Suggested sidebar:

Dashboard

Projects

- Programmes
- All Projects
- Project Proposals
- Active Projects
- Completed Projects

Work Management

- My Tasks
- Team Tasks
- Workplans
- Calendar
- Milestones

Finance

- Budgets
- Budget Allocations
- Expense Requests
- Expenditures
- Commitments
- Payments
- Financial Reports

Grants & Donors

- Donors
- Grants
- Funding Agreements
- Disbursements
- Donor Reports

Procurement

- Procurement Plans
- Purchase Requests
- Suppliers
- Tenders
- Purchase Orders
- Contracts

Monitoring & Evaluation

- Results Frameworks
- Indicators
- Data Collection
- Evaluations

Beneficiaries

- Beneficiary Records
- Programme Participation
- Service Requests
- Feedback & Complaints

Reports & Analytics

AI Assistant

App Center

Administration

- Organizations
- Teams
- Users
- Roles & Permissions
- Approval Workflows
- Settings
- Audit Logs

Only expose navigation items that correspond to implemented features and permitted actions.

Maintain clear separation between NGO, donor, beneficiary, and platform administration interfaces.

---

# 16. RESTRUCTURE WITHOUT BREAKING EXISTING FUNCTIONALITY

Follow these rules strictly.

1. Preserve working features.
2. Reuse existing components where appropriate.
3. Avoid unnecessary package changes.
4. Avoid wholesale rewrites.
5. Introduce database migrations safely.
6. Preserve existing project and task data.
7. Keep APIs backward compatible where feasible.
8. Identify breaking changes before implementing them.
9. Use feature flags for significant migrations where useful.
10. Maintain test coverage.

Do not delete existing database tables without verified migration and rollback procedures.

Do not remove SSO-related code until the replacement authentication workflow is tested.

Do not remove central database configuration until all application-owned data has been migrated and verified.

---

# 17. MIGRATION EXECUTION STRATEGY

Implement the restructuring in controlled phases.

## PHASE 1 — REPOSITORY AND ARCHITECTURE AUDIT

Deliver:

- Current architecture report
- SSO dependency inventory
- Central database dependency inventory
- Existing feature inventory
- Security assessment
- Data migration assessment
- Target architecture
- Implementation roadmap

Do not modify production data.

## PHASE 2 — STANDALONE FOUNDATION

Implement:

- Dedicated database configuration
- Local authentication
- User management
- Session management
- Role-based permissions
- Organization management

Test the new foundation independently.

## PHASE 3 — DATA MIGRATION

Implement:

- Data mapping
- Migration scripts
- User identity migration
- Organization migration
- Project migration
- Task migration
- Financial data migration
- Attachment migration
- Reconciliation reports

Run migration tests against a copy of the source data.

## PHASE 4 — REMOVE RUNTIME DEPENDENCIES

After successful migration testing:

- Replace SSO guards.
- Replace central database queries.
- Update authentication flows.
- Update API clients.
- Update organization lookups.
- Update permission checks.
- Remove obsolete runtime configuration.

Keep a rollback path until production verification is complete.

## PHASE 5 — RESTRUCTURE APPLICATION MODULES

Organize existing functionality into clear business domains.

Avoid duplicating services and database models.

Preserve existing UI features.

## PHASE 6 — NGO-SPECIFIC CAPABILITIES

Implement missing functionality incrementally:

- Financial management
- Grant management
- Procurement
- MEAL
- Beneficiary management
- Donor portal
- Data collection

Prioritize working end-to-end workflows.

## PHASE 7 — CLICKUP-STYLE ENHANCEMENTS

Add advanced productivity features:

- Gantt
- Timeline
- Workload
- Custom fields
- Automation
- Docs
- Whiteboards
- Advanced reporting

Only after core operations are stable.

## PHASE 8 — AI AND EXTERNAL INTEGRATIONS

Implement:

- OpenAI integration
- KoboToolbox connector
- ODK connector
- App Center
- Optional productivity integrations

Do not make external integrations mandatory for application startup.

---

# 18. TESTING AND VALIDATION

Add tests for:

### Authentication

- Registration
- Login
- Logout
- Password reset
- Session expiration
- Unauthorized access

### Organization Isolation

- Cross-organization data access
- Workspace switching
- Organization invitations
- Project membership

### Project Management

- Project creation
- Task assignment
- Project status updates
- Task completion

### Financial Management

- Budget approval
- Budget allocation
- Expense approval
- Overspending prevention
- Financial corrections

### Donor Access

- Authorized project access
- Restricted financial visibility
- Unrelated project denial
- Confidential beneficiary data protection

### Data Migration

- Record counts
- Data relationships
- User mapping
- Financial balances
- File integrity
- Permission equivalence

### Integration Independence

Verify that the application continues functioning when:

- The former SSO service is unavailable.
- The former central database is unavailable.
- KoboToolbox is unavailable.
- The OpenAI API is unavailable.
- Other external services are unavailable.

The standalone application must start and provide core functionality without the former SSO or central database.

---

# 19. DEPLOYMENT REQUIREMENTS

Provide standalone deployment configuration.

Include:

- Dedicated backend
- Dedicated database
- Frontend deployment
- Environment configuration
- Database migrations
- Secure secret management
- File storage configuration
- HTTPS configuration
- Backup and restore procedures
- Health checks
- Logging and monitoring

Create or update:

- `.env.example`
- Docker configuration
- Database migration commands
- Deployment documentation
- Backup instructions
- Recovery instructions

Never hardcode production credentials.

Do not expose database credentials to the frontend.

---

# 20. EXPECTED DELIVERABLES

At the end of each phase, provide:

1. Summary of changes.
2. Files modified.
3. Database migrations.
4. New dependencies.
5. Removed dependencies.
6. Security changes.
7. Tests executed.
8. Test results.
9. Remaining issues.
10. Next implementation phase.

Do not claim that functionality works without verification.

Do not silently skip requirements.

Do not create UI-only implementations disconnected from backend functionality.

---

# 21. IMPORTANT ARCHITECTURAL RULES

Follow these principles:

### Rule 1 — Standalone Application

No mandatory dependency on external SSO or the former central database.

### Rule 2 — Preserve Existing Work

Do not destroy or unnecessarily replace existing functionality.

### Rule 3 — Secure Multi-Tenancy

Organizations must remain isolated while supporting authorized donor and partner collaboration.

### Rule 4 — Financial Integrity

Budgets, commitments, expenditure, and payments must be accurately tracked and auditable.

### Rule 5 — Controlled External Integrations

External applications are optional integrations, not dependencies for core operation.

### Rule 6 — Modular Architecture

Keep project management, finance, grants, procurement, beneficiaries, and MEAL as clearly separated business domains.

### Rule 7 — Incremental Implementation

Complete and test foundational functionality before expanding into advanced modules.

### Rule 8 — Database Ownership

The application owns its dedicated database schema and migrations.

### Rule 9 — Backend Authorization

Every sensitive action must be authorized server-side.

### Rule 10 — Production Readiness

Security, migration, backup, recovery, observability, and testing are mandatory engineering requirements.

---

# 22. START NOW

Begin by inspecting the existing repository.

First deliver:

1. Current application architecture.
2. Existing SSO implementation analysis.
3. Central database dependency analysis.
4. Existing feature inventory.
5. Proposed standalone architecture.
6. Proposed database schema changes.
7. Data migration and rollback strategy.
8. Security and authorization strategy.
9. Implementation phases.
10. Risks and dependencies.

Then begin with the standalone authentication and database foundation only when the audit shows a safe migration path.

Do not start by deleting the existing SSO implementation or central database references.

Do not discard existing data.

Do not redesign unrelated working modules unnecessarily.

The final application must be a completely independent, secure, multi-organization NGO, donor, and beneficiary project management platform with its own authentication, database, administration, and deployment infrastructure.
