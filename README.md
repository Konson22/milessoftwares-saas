# MILES GLOBAL SOFTWARES
# Master Implementation Specification — Multi-Tenant SaaS Platform

## ROLE

Act as a Principal Software Architect, Senior Laravel Engineer, SaaS Platform Architect, Database Engineer, Security Engineer, and Senior React/TypeScript Developer.

Build Miles Global Softwares as a production-oriented, multi-tenant SaaS platform from scratch.

This platform is owned by Miles Global Link Co. Ltd and will host multiple independent business software applications under a common identity, organization, subscription, and access management infrastructure.

Do not attempt to implement every business application simultaneously. Build the central foundation first and prepare clear integration contracts for applications developed separately.

Work incrementally, following the phases below.

---

# 1. PROJECT OVERVIEW

**Parent Company:** Miles Global Link Co. Ltd

**Software Brand:** Miles Global Softwares

**Primary Domain:** milesoftwares.com

The platform must support:

1. Public marketing website
2. Central Authentication / SSO
3. Customer Dashboard
4. Internal System Administration
5. Multi-tenant business workspaces
6. Central organization management
7. Departments and branches
8. Users, roles, and permissions
9. Multiple application subscriptions
10. Shared Finance & Accounting integration
11. Shared HR & Payroll integration
12. Other optional shared business modules
13. SaaS application registration and integrations
14. Secure tenant isolation
15. Subscription and entitlement management
16. Audit logging and administrative oversight

# 2. DOMAIN ARCHITECTURE

Implement the following hostname structure:

| Domain | Function |
|---|---|
| milesoftwares.com | Public marketing website |
| www.milesoftwares.com | Redirect to primary website |
| auth.milesoftwares.com | Central authentication |
| app.milesoftwares.com | Customer dashboard |
| admin.milesoftwares.com | Internal system administration |
| {business}.milesoftwares.com | Individual customer workspace |

Each registered business receives a unique subdomain.

Examples:

manggala.milesoftwares.com

abcpharmacy.milesoftwares.com

jubaschool.milesoftwares.com

Within a business workspace, use module routing:

- /retail
- /pharmacy
- /school
- /finance
- /hr
- /payroll
- /procurement
- /inventory
- /restaurant

Only show modules available to the organization and authorized for the current user.

Reserve platform hostnames such as auth, app, admin, www, api, mail, and support.

Business slugs must be validated and unique. Support controlled slug changes without breaking identity references.

Use wildcard DNS and TLS for first-level customer subdomains.

Do not assume wildcard certificates secure arbitrary multi-level hostnames.

Important: The URL design is a product requirement, not a reason to merge all applications into one deployment.

Design a business workspace gateway or equivalent routing mechanism so independently deployed applications can operate under module paths when feasible.

Evaluate routing, application assets, cookies, CSRF, WebSockets, callbacks, APIs, and offline/PWA behavior before committing to this approach.

If a product is incompatible with path-based deployment, document the limitation and propose an explicit alternative rather than silently rewriting that application.

---

# 3. TECHNOLOGY AND PROJECT STRUCTURE

Recommended technologies:

Backend:
- Laravel, latest stable compatible version
- PHP supported by the selected Laravel version
- PostgreSQL
- Redis
- Laravel Queues
- Laravel Scheduler

Frontend:
- React
- TypeScript
- Inertia.js
- Tailwind CSS
- shadcn/ui or equivalent accessible components

Testing:
- Pest or PHPUnit
- Frontend component tests
- End-to-end authentication and tenancy tests

Build the central platform as a modular monolith.

Suggested modules:

- Identity
- Organizations
- Departments
- Branches
- Memberships
- Applications
- Authorization
- Subscriptions
- Entitlements
- Tenant Provisioning
- Notifications
- Audit
- Administration
- Marketing

Do not create microservices simply because modules are logically separate.

Keep module boundaries explicit through interfaces, services, policies, and domain events.

---

# 4. PUBLIC MARKETING WEBSITE

Domain:

milesoftwares.com

Develop a professional public-facing software website.

Pages:

1. Homepage
2. Products
3. Individual Product Details
4. Solutions by Industry
5. Pricing
6. About Us
7. Contact
8. Request Demo
9. Privacy Policy
10. Terms and Conditions

Application catalog:

- Retail POS
- Pharmacy ERP
- School Management
- Finance & Accounting
- HR & Payroll
- Inventory Management
- Restaurant POS
- Procurement

Each product should support:

- Product name and description
- Target industry
- Features
- Product screenshots
- Supported platforms
- Pricing information
- Demo requests
- Trial eligibility
- Release status
- Get Started action

Differentiate available products from planned or beta products.

Do not invent testimonials, customers, certifications, integrations, or capabilities.

Marketing pages must be SEO-friendly, responsive, accessible, and fast.

Provide secure contact and demo request forms with validation, spam protection, stored submissions, and notifications.

Use a reusable product catalog rather than maintaining duplicated hardcoded product descriptions.

---

# 5. CENTRAL AUTHENTICATION

Domain:

auth.milesoftwares.com

Implement centralized identity management.

Features:

- User registration
- Login and logout
- Email verification
- Forgot password
- Password reset
- Account recovery
- MFA for privileged accounts
- Account suspension
- Session management
- Login audit events
- Security notifications

Support centralized SSO for all registered SaaS applications.

Use a standards-compliant OAuth 2.0 / OpenID Connect solution.

Evaluate Laravel Passport, a vetted OIDC extension, or a dedicated identity provider such as Keycloak.

Do not assume Passport alone implements full OIDC.

Use Authorization Code with PKCE where appropriate.

Validate redirect URIs exactly.

Implement state and nonce protections where applicable.

Never expose long-lived credentials to browser JavaScript.

Avoid sharing privileged authentication cookies across business subdomains.

Central authentication proves identity; it does not grant unrestricted tenant or application access.

User identities must remain stable across organizations and products.

---

# 6. CENTRAL ORGANIZATION MANAGEMENT

Each customer may create one or multiple organizations, subject to platform policy.

An organization represents a distinct customer business.

Examples:

- Manggala Trading Ltd
- ABC Pharmacy Ltd
- Juba International School

Organization properties:

- Unique ID
- Legal name
- Display name
- Business slug
- Business type
- Contact information
- Country
- Default currency
- Timezone
- Status
- Owner membership
- Subscription information

Use immutable organization IDs internally.

Never use a subdomain string as the sole tenant identifier.

Support organization statuses:

- Pending
- Active
- Suspended
- Archived

Archived organizations must follow a defined retention and deletion policy.

A user may belong to multiple organizations.

Organization switching must explicitly revalidate membership and access.

---

# 7. DEPARTMENTS, BRANCHES, AND POSITIONS

Implement centralized organization structure management.

Core entities:

- Organizations
- Departments
- Branches
- Positions
- Organization Memberships

Example departments:

- Management
- Finance
- Human Resources
- Procurement
- Sales
- Operations
- Inventory

Departments must be organization-specific, not global shared records.

Branches may include:

- Name
- Code
- Address
- Status
- Parent organization
- Manager assignment, where appropriate

Allow organizations to have one or many branches.

Do not automatically assume every department belongs to a branch.

Some departments, such as Procurement, Finance, and HR, may serve the entire organization.

Support:

- Company-wide departments
- Branch-specific departments where appropriate
- Multi-branch employee assignments
- Department memberships
- Branch assignments
- Position/job-title management

Departments and positions are business structure, not authorization roles.

Being assigned to Finance Department must not automatically grant accounting privileges.

---

# 8. CENTRAL USERS AND MEMBERSHIPS

Separate global identity from organization membership.

One user may belong to multiple organizations.

One organization may contain multiple users.

An employee record is not automatically a login account.

A user may have access to several SaaS applications.

Support:

- Invite user
- Accept invitation
- Remove membership
- Suspend membership
- Assign organization responsibilities
- Assign application access
- Revoke access
- View authorized organizations
- Switch active organization

Invitation tokens must expire and be single-use.

Prevent invitations from granting unauthorized privileges.

Do not allow one organization to discover another organization's user directory or private membership details.

---

# 9. APPLICATION REGISTRY

System Administrators must be able to register and configure SaaS applications.

Examples:

retail
pharmacy
school
finance
hr
payroll
procurement
inventory
restaurant

Application registry fields:

- Application ID
- Name
- Unique code
- Description
- Category
- Status
- Integration endpoint
- Authentication client configuration
- Supported capabilities
- Available subscription plans
- Role catalog version
- Permission catalog version
- Tenant provisioning method

Applications may be:

- Standalone products
- Shared modules
- Industry-specific applications
- Optional add-on modules

Do not duplicate application definitions across multiple configuration systems.

Support application versioning and compatibility with existing integrations.

---

# 10. CENTRAL ROLES AND PERMISSIONS

This is one of the most important architectural requirements.

Implement a hybrid authorization model.

The Central Platform manages:

- Global user identity
- Organization membership
- Application entitlements
- Application role assignments
- Branch and organizational scope assignments
- Delegation authority

Individual applications own and enforce their operational permissions.

## 10.1 Distinguish concepts

Department = Organizational unit

Position = Employee job title

Role = Group of application permissions

Permission = Authorized action

Scope = Where an action is allowed

Entitlement = What the organization has subscribed to

Do not confuse these concepts.

## 10.2 Application-specific roles

Example Retail POS roles:

- Business Owner
- Branch Manager
- Cashier
- Sales Attendant
- Storekeeper
- Procurement Requester

Example Finance roles:

- Finance Manager
- Accountant
- Accounts Payable Officer
- Accounts Receivable Officer
- Cashier
- Auditor

Example HR roles:

- HR Manager
- HR Officer
- Payroll Officer
- Payroll Approver
- Employee

Example Pharmacy roles:

- Pharmacy Manager
- Pharmacist
- Cashier
- Storekeeper

Example School roles:

- School Administrator
- Headteacher
- Teacher
- Registrar
- Bursar

These are configurable role templates and must not be implemented as hardcoded application-wide assumptions.

## 10.3 Multiple roles

Allow a user to hold multiple roles across different applications.

Also allow multiple roles within the same application.

Example:

User A:
- Retail Branch Manager — Branch A
- Retail Sales Viewer — Branch B
- Finance Accountant — Organization-wide
- Procurement Requester — Branch A

Each assignment must carry its own scope.

Do not merge scopes merely because multiple assignments belong to the same user.

## 10.4 Role assignment model

Model authorization around:

User
Organization
Application
Role
Scope Type
Scope Identifier

Scope types may include:

- Organization
- Branch
- Department
- Explicit resource, when supported

Do not assume every role supports every scope type.

Each application's permission catalog must define the valid scopes.

## 10.5 Security requirements

Enforce permissions on backend requests.

Never rely only on frontend route guards or hidden sidebar links.

Prevent:
- Cross-organization data access
- Unauthorized branch access
- Privilege escalation
- Unauthorized self-role assignment
- Unauthorized delegation
- Assignment of roles for unsubscribed products

Application backends must enforce effective permissions against verified tenant context.

Use explicit separation-of-duties constraints for financial and other sensitive approvals.

A user should not automatically approve a financial transaction merely because they also created it.

## 10.6 Roles UI

Build a user-friendly access management interface.

Features:

- User directory
- User detail page
- Assigned applications
- Multiple role assignments
- Branch scope selection
- Organization-wide assignments
- Permission preview
- Revoke assignment
- Role assignment audit history

Provide reusable role and scope selection components.

---

# 11. CUSTOMER DASHBOARD

Domain:

app.milesoftwares.com

This is the customer-facing control panel.

Pages:

- Dashboard
- My Organizations
- Create Organization
- Organization Settings
- Departments
- Branches
- Users & Invitations
- Application Access
- My Applications
- Plans & Subscriptions
- Billing History
- Profile & Security

Business Owners must be able to manage their own organizations.

They may invite users and assign roles within allowed delegation limits.

The customer dashboard must never expose other organizations' private data.

Provide organization switching for users belonging to several businesses.

The dashboard should link users to the correct business workspace.

Example:

manggala.milesoftwares.com

---

# 12. BUSINESS WORKSPACE

Each organization gets a unique workspace.

Example:

manggala.milesoftwares.com

Build a workspace shell containing:

- Organization identity
- Application launcher
- Sidebar navigation
- User profile
- Organization switcher
- Branch selector where relevant
- Notifications
- Account security access

Only display modules the organization is entitled to use and the user can access.

Do not implement all operational modules inside the central platform.

Create module integration contracts and placeholder entry points only where needed.

Existing or independently developed applications must be able to connect without being rebuilt unnecessarily.

---

# 13. SHARED FINANCE & ACCOUNTING

Finance & Accounting is a separate SaaS product that may also be used as a shared module.

Other products may submit authorized financial events into Finance.

Examples:

Retail → Sales and payment events

Pharmacy → Dispensing sales and purchasing events

School → Fee invoices and payments

HR Payroll → Approved payroll journals

Procurement → Approved purchasing and liability events

Finance owns:

- Chart of Accounts
- General Ledger
- Journals
- Receivables
- Payables
- Cash & Banking
- Financial Statements
- Budget Accounting

Source applications must not write directly to Finance's database.

Define secure integration APIs and idempotent business event contracts.

Each event must contain trusted organization context and a unique source transaction identifier.

Support retries without duplicate postings.

Allow Finance to be purchased independently or as part of a bundle.

Do not build the entire Finance application during central platform development.

---

# 14. SHARED HR & PAYROLL

HR & Payroll must also operate as a standalone SaaS application and a shared module.

HR owns:

- Employee Directory
- Employment Records
- Contracts
- Departments and Positions where extended employment data is needed
- Employee Assignments
- Leave
- Attendance

Payroll owns:

- Salary Structures
- Payroll Periods
- Payroll Runs
- Payslips
- Payroll Approvals

HR must integrate with organization structure managed by the central platform.

Employees may exist without user accounts.

A user account may be linked to an employee record through a secure identity reference.

Other applications may reference verified employee identifiers.

Examples:

Retail uses employee references for cashier shifts.

School uses employee references for teaching staff.

Pharmacy uses employee references for pharmacy personnel.

Payroll sends approved accounting entries to Finance.

Never allow applications to freely read payroll salaries, employee bank details, or sensitive HR records.

Support separate HR and Payroll permissions.

Do not implement the complete HR and Payroll system inside the central platform.

---

# 15. OTHER SHARED APPLICATION MODULES

Prepare future integration support for:

- Procurement
- Inventory
- Document Management
- Projects & Budgets

Avoid building duplicate shared functionality across individual applications.

However, do not automatically centralize every similar-looking workflow.

For each shared module, define:

- System of record
- Ownership of core entities
- Integration events
- Authorized consuming applications
- Tenant boundaries
- Error and retry policies

For example, purchasing may be centralized while pharmacy-specific medicine batch requirements remain inside the Pharmacy application.

---

# 16. SYSTEM ADMIN APPLICATION

Domain:

admin.milesoftwares.com

This application is strictly for Miles Global Softwares internal staff.

Modules:

1. Overview
2. Organizations
3. Global Users
4. Applications Registry
5. Subscription Plans
6. Customer Subscriptions
7. Entitlements
8. Internal Admin Users
9. Internal Admin Roles
10. Audit Logs
11. Security Events
12. Demo Requests
13. Platform Settings

Platform Admin role templates:

- Super Admin
- Operations Admin
- Subscription Manager
- Support Officer
- Billing Administrator
- Security Auditor

Keep internal platform roles separate from business organization roles.

Do not grant internal administrators unrestricted access to customer operational data.

Any support access to customer environments must be explicit, scoped, temporary, justified, and audited.

Require MFA for privileged platform accounts.

Never expose the System Admin navigation inside the ordinary customer dashboard.

---

# 17. SUBSCRIPTIONS AND ENTITLEMENTS

Each organization may subscribe to multiple products.

Examples:

Retail only

Retail + Finance

Pharmacy + Finance + HR

School + Finance + HR + Payroll

Support:

- Application plans
- Product bundles
- Trial periods
- Subscription activation
- Subscription suspension
- Subscription expiration
- Renewal dates
- Plan changes
- Application entitlements
- Feature limits

Keep commercial subscription billing separate from customer operational Finance & Accounting.

The central platform bills customers for SaaS usage.

The Finance application handles customers' own business financial records.

For MVP, implement manually administered subscriptions and entitlements before integrating online payments.

All application access decisions must be validated server-side.

---

# 18. TENANT PROVISIONING

Build a reliable, idempotent provisioning workflow.

When a customer creates a business:

1. Validate their identity.
2. Create the organization.
3. Reserve a unique subdomain slug.
4. Establish the owner's membership.
5. Establish default organization configuration.
6. Initialize authorized application entitlements.
7. Provision only the selected applications.
8. Record provisioning status.
9. Provide the customer with the workspace URL.

Use queued jobs for potentially slow provisioning tasks.

Handle provisioning failures and retries safely.

Do not leave partially provisioned tenants appearing fully active.

Provisioning must not require manually creating DNS records for each first-level business subdomain.

---

# 19. DATABASE DESIGN

Design migrations and relationships before implementing UI screens.

Minimum platform entities:

Identity:
- users
- authentication_events

Organizations:
- organizations
- organization_memberships
- departments
- branches
- positions

Applications:
- applications
- application_clients
- application_roles
- application_permissions
- role_permission_mappings

Authorization:
- application_access_grants
- role_assignments
- role_assignment_scopes

Commercial:
- plans
- plan_features
- subscriptions
- subscription_items
- entitlements

Operations:
- invitations
- provisioning_jobs
- audit_logs
- demo_requests

Use proper foreign keys, constraints, indexes, and unique identifiers.

Determine final table names after reviewing domain boundaries and framework conventions.

Avoid storing permissions exclusively as unvalidated JSON.

Use transactional consistency where appropriate.

Use immutable IDs for critical entities.

Ensure financial and security-sensitive audit records are tamper-resistant.

Document organization data deletion and retention behavior.

---

# 20. SECURITY REQUIREMENTS

Treat tenant isolation as a release-blocking security requirement.

Implement:

- Trusted hostname validation
- Secure tenant resolution
- Server-side authorization
- CSRF protection
- XSS protection
- SQL injection prevention
- Rate limiting
- Secure cookies
- Secret management
- MFA
- Scoped access tokens
- Session invalidation
- Privileged action auditing
- Protection against horizontal privilege escalation
- Protection against mass assignment
- Strict CORS and redirect policies

Hostnames must be validated against known, reserved, or provisioned tenant records.

Unknown business subdomains must not resolve to another organization's workspace.

Do not trust organization IDs or role claims supplied directly by the frontend.

Use automated cross-tenant security tests.

---

# 21. UI/UX REQUIREMENTS

Design a polished, consistent enterprise interface.

Visual direction:

- Professional light theme
- Blue primary accent
- Clean typography
- Compact reusable tables
- Responsive sidebar navigation
- Consistent forms and dialogs
- Accessible inputs and buttons
- Reusable badges and status indicators
- Good loading and error states
- Pagination and filtering
- Mobile responsiveness

Create shared UI components for:

- DataTable
- PageHeader
- StatusBadge
- SearchFilterBar
- FormField
- ConfirmationDialog
- PermissionSelector
- RoleAssignmentEditor
- OrganizationSwitcher
- BranchSelector
- ApplicationLauncher

Filter navigation according to actual authorization, but always enforce permissions on the backend.

---

# 22. DEVELOPMENT PHASES

Implement in the following sequence.

## Phase 1 — Foundation

- Initialize repository
- Configure Laravel and frontend stack
- Set up PostgreSQL and Redis
- Establish modules and coding conventions
- Set up tests, linting, and CI
- Configure host-based routing
- Implement initial database structure

## Phase 2 — Central Authentication

- User registration and login
- Verification and recovery
- MFA
- OAuth/OIDC architecture
- Secure sessions
- Login audit events

## Phase 3 — Organizations and Multi-Tenancy

- Organizations
- Memberships
- Business slugs
- Hostname resolution
- Subdomain provisioning
- Tenant isolation tests

## Phase 4 — Organization Structure and RBAC

- Departments
- Branches
- Positions
- Application role registry
- Permission catalog
- Scoped role assignments
- Access management UI
- Separation-of-duties policies

## Phase 5 — System Administration

- Internal administrator access
- Organizations management
- Application registry
- Plans
- Entitlements
- Security logs
- System administration UI

## Phase 6 — Customer Dashboard

- Organization dashboard
- Application launcher
- Users and invitations
- Organization settings
- Branch and department management
- Subscription overview

## Phase 7 — Public Marketing Website

- Homepage
- Product catalog
- Product details
- Pricing
- Contact and demo forms
- SEO
- Responsive design

This phase may be implemented earlier or in parallel if independent.

## Phase 8 — Application Integration Framework

- Client registration
- SSO integration contracts
- Application entitlement verification
- Scoped authorization contracts
- Provisioning interfaces
- Secure integration event handling

Connect one sample application end to end.

## Phase 9 — Shared Module Integration

- Finance integration contract
- HR integration contract
- Payroll integration contract
- Organization identifier mapping
- Idempotent event processing
- Integration health reporting

## Phase 10 — Production Readiness

- Security review
- Tenant isolation tests
- Load testing
- Queue and retry validation
- Backup and restoration tests
- HTTPS and wildcard DNS verification
- Deployment documentation
- Monitoring and operational runbooks

---

# 23. TESTING REQUIREMENTS

Every phase must include appropriate automated tests.

Test:

- Authentication
- Authorization
- Multi-organization membership
- Organization switching
- Tenant hostname resolution
- Unknown subdomain rejection
- Role assignments
- Branch-scoped access
- Organization-wide access
- Invalid delegation
- Subscription restrictions
- Expired entitlements
- Duplicate provisioning requests
- Audit logging
- Privileged admin actions
- Cross-tenant access attempts

Include negative tests, not only successful workflows.

Verify that one customer cannot access another customer's records even if they know the record ID.

Run database migrations and frontend builds.

Report actual test results, not assumed results.

---

# 24. IMPLEMENTATION RULES

Before implementation:

1. Inspect the repository and environment.
2. Identify existing code or dependencies.
3. Confirm architecture and version compatibility.
4. Produce a phased implementation plan.
5. Identify technical risks and design decisions.

During implementation:

1. Work one phase at a time.
2. Reuse established packages when appropriate.
3. Avoid duplicating modules.
4. Implement migrations safely.
5. Add authorization before exposing features.
6. Add tests alongside functionality.
7. Preserve working functionality.
8. Document important decisions.
9. Keep code modular and maintainable.
10. Avoid unnecessary infrastructure complexity.

After each phase:

1. Run applicable tests.
2. Verify frontend build.
3. Summarize completed work.
4. List modified files and migrations.
5. Identify remaining risks.
6. Explain the next phase.

Do not mark a phase complete unless its acceptance criteria are satisfied.

Do not automatically deploy to production.

Do not perform destructive database operations without explicit approval.

---

# 25. INITIAL EXECUTION INSTRUCTION

Start by inspecting the current project directory.

If it is empty, create the central platform foundation.

If an existing project exists, inspect it before making changes.

First produce:

1. Proposed application architecture.
2. Module ownership matrix.
3. Database relationship design.
4. Identity and authorization design.
5. Multi-tenant routing design.
6. SaaS application integration strategy.
7. Phase-by-phase implementation plan.
8. Project folder structure.
9. Technical risks and mitigations.

Then implement **Phase 1 — Foundation**.

Do not attempt to build all ten phases in one execution.

Maintain an implementation progress document showing completed tasks, outstanding work, decisions, and verification results.

The final objective is a secure, maintainable, multi-tenant SaaS ecosystem where multiple business applications share centralized identity, organization management, subscription infrastructure, and authorized shared business services without duplicating functionality or compromising tenant isolation.