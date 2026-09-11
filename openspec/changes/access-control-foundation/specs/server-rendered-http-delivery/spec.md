# Server-Rendered HTTP Delivery Specification

## Purpose

Define safe HTML-over-the-wire delivery for full navigation and HTMX interactions without duplicating application behavior.

## MODIFIED Requirements

### Requirement: Central HTTP Routing

Every HTTP request MUST pass through a central route contract that distinguishes path and method. Protected business routes MUST require an authenticated session and an authorized role before handler dispatch. Unauthenticated requests to protected routes MUST be redirected to the login endpoint, and unauthorized authenticated requests MUST be rejected with status 403. Unknown paths MUST return 404; known paths invoked with unsupported methods MUST return 405 and identify allowed methods.
(Previously: Route dispatching executed matching handlers without pre-handler authentication or role authorization guards.)

#### Scenario: Dispatch a known route
- GIVEN a registered path and method
- WHEN a request matches both and satisfies any required authentication or authorization guard
- THEN the corresponding server-rendered response MUST be dispatched

#### Scenario: Redirect unauthenticated browser request
- GIVEN an unauthenticated browser user
- WHEN a protected route is requested via a GET request
- THEN the system MUST respond with HTTP status 303
- AND the Location header MUST redirect to /login
- AND the protected handler MUST NOT execute

#### Scenario: Intercept unauthenticated HTMX request
- GIVEN an unauthenticated or expired session
- WHEN a protected route is requested via HTMX
- THEN the system MUST respond with HTTP status 200
- AND the response MUST include header HX-Redirect set to /login
- AND no partial login fragment MUST be rendered into the target element
- AND the protected handler MUST NOT execute

#### Scenario: Reject authenticated user lacking role authorization
- GIVEN an authenticated user whose active role lacks permission for the requested route
- WHEN the user attempts to access the route
- THEN the system MUST return HTTP status 403 Forbidden
- AND the system MUST NOT redirect the user to /login
- AND the protected handler MUST NOT execute

#### Scenario: Dispatch a public route without authentication
- GIVEN a public route such as GET /health or GET /login
- WHEN an unauthenticated user requests the route
- THEN the route handler MUST be dispatched without requiring credentials

#### Scenario: Reject an unsupported request
- GIVEN a path is unknown or its method is unsupported
- WHEN the request is dispatched
- THEN it MUST return 404 for the unknown path or 405 for the unsupported method
- AND a 405 response MUST identify the allowed methods

---

### Requirement: Validation, Redirects, and Notifications

Server validation MUST be authoritative. Invalid submissions MUST return 422 with field feedback as a full page or HTMX fragment. Successful normal submissions MUST use Post/Redirect/Get with HTTP status 303 See Other; HTMX responses MUST use documented `HX-Redirect` and `HX-Trigger` conventions where navigation or notifications are required.
(Previously: Successful submissions did not specify the exact 303 status code for Post/Redirect/Get redirects.)

#### Scenario: Reject invalid input
- GIVEN a normal or HTMX submission contains invalid input
- WHEN validation runs
- THEN the response MUST have status 422
- AND it MUST preserve safe input and expose field-specific feedback in the matching representation

#### Scenario: Complete a valid submission
- GIVEN a submission is valid
- WHEN the state-changing action completes
- THEN normal navigation MUST redirect using HTTP status 303 See Other to a safe GET target
- AND HTMX MUST communicate navigation or notification through the documented HX headers

## ADDED Requirements

### Requirement: Infrastructure Health Liveness and Probe Boundaries

The system MUST provide an anonymous GET /health endpoint returning a minimal availability check without exposing database credentials, operational records, session diagnostics, or internal system state. State-changing probe endpoints (POST /health) MUST NOT be exposed in production and MAY exist only outside production environments for integration harness testing.

#### Scenario: Access public liveness probe
- GIVEN an unauthenticated client
- WHEN a GET request is issued to /health
- THEN the system MUST respond with HTTP status 200
- AND the response MUST NOT expose database credentials, operational records, or session identifiers

#### Scenario: Reject probe mutation in production
- GIVEN the application is running in a production environment
- WHEN a POST request is issued to /health
- THEN the system MUST NOT expose or execute the diagnostic probe mutation

---

### Requirement: Authenticated Layout Shell and Action Visibility

The rendered application shell MUST expose the authenticated user's username, human-readable role badge, and a touch-accessible logout action. The presentation layer MUST conditionally suppress operational action controls that the user's role is not authorized to execute. UI control suppression MUST remain an ergonomic convenience, while server-side authorization guards remain authoritative.

#### Scenario: Render authenticated shell context
- GIVEN an authenticated user session
- WHEN an operational page is rendered
- THEN the topbar and navigation shell MUST display the username and human-readable role
- AND a touch-accessible logout action with minimum target size of 44px MUST be available

#### Scenario: Suppress unauthorized mutation controls in UI
- GIVEN an authenticated user with role cajero
- WHEN the product catalog page is rendered
- THEN the user MUST view catalog items
- AND action controls for creating categories, registering products, updating prices, or deactivating products MUST be omitted from the rendered HTML
