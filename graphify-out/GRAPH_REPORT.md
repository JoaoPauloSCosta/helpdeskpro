# Graph Report - helpdesk  (2026-11-08)

## Corpus Check
- Large corpus: 535 files · ~230,452 words. Semantic extraction will be expensive (many Claude tokens). Consider running on a subfolder.

## Summary
- 5323 nodes · 12399 edges · 197 communities (150 shown, 47 thin omitted)
- Extraction: 97% EXTRACTED · 3% INFERRED · 0% AMBIGUOUS · INFERRED: 314 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Shared UI Components
- Department & Form Inputs
- Chat Widget (Vue)
- Inertia Pages & Roles
- Buttons & Branding
- SLA & Timeline UI
- Auth Controllers
- Login & Form Requests
- Chat Session Actions
- Chat Cancel & Conflicts
- Role Tests
- Badges & Justification Modal
- Category Actions
- Ticket Status Actions
- Authenticated Layout
- Ticket Edit Tests
- SLA State Enum
- Action Exceptions
- Chat Admin Tests
- Role Actions & Controller
- Attachments Config
- Role Permission Matrix Tests
- User Invitation Notification
- Ticket Pending Tests
- Alert & Modal
- Bar Charts
- Ticket List Filters
- Permission Catalog Tests
- Comment & Attachment Actions
- Ticket Status Enum
- NPM Dependencies
- Ticket Reopening Tests
- Resolve & Update Ticket
- User Edit Tests
- Assign Ticket & Roles
- Audit Coverage Ledger Script
- Ticket Closing Tests
- Findings Validator Script
- Performance Tests
- Category Edit Tests
- Community 40
- Community 41
- Community 42
- Community 43
- Community 44
- Community 45
- Community 46
- Community 47
- Community 48
- Community 49
- Community 50
- Community 51
- Community 52
- Community 53
- Community 54
- Community 55
- Community 56
- Community 57
- Community 58
- Community 59
- Community 60
- Community 61
- Community 62
- Community 63
- Community 64
- Community 65
- Community 66
- Community 67
- Community 68
- Community 69
- Community 70
- Community 71
- Community 72
- Community 73
- Community 74
- Community 75
- Community 76
- Community 77
- Community 78
- Community 79
- Community 80
- Community 81
- Community 82
- Community 83
- Community 84
- Community 85
- Community 86
- Community 87
- Community 88
- Community 89
- Community 90
- Community 91
- Community 92
- Community 93
- Community 94
- Community 95
- Community 96
- Community 97
- Community 98
- Community 99
- Community 100
- Community 101
- Community 102
- Community 103
- Community 104
- Community 105
- Community 106
- Community 107
- Community 108
- Community 109
- Community 110
- Community 111
- Community 112
- Community 113
- Community 114
- Community 116
- Community 117
- Community 118
- Community 119
- Community 120
- Community 121
- Community 122
- Community 124
- Community 125
- Community 126
- Community 127
- Community 128
- Community 129
- Community 130
- Community 131
- Community 132
- Community 133
- Community 134
- Community 135
- Community 136
- Community 137
- Community 138
- Community 139
- Community 140
- Community 141
- Community 142
- Community 143
- Community 144
- Community 145
- Community 146
- Community 147
- Community 149
- Community 150
- Community 151
- Community 152
- Community 153
- Community 154
- Community 155
- Community 157
- Community 158
- Community 160
- Community 161
- Community 162
- Community 163
- Community 164
- Community 166
- Community 167
- Community 168
- Community 169
- Community 170
- Community 171
- Community 172
- Community 173
- Community 174
- Community 175
- Community 177
- Community 178
- Community 179

## God Nodes (most connected - your core abstractions)
1. `User` - 380 edges
2. `Ticket` - 270 edges
3. `TicketStatus` - 242 edges
4. `vue` - 88 edges
5. `Role` - 86 edges
6. `AdminEvent` - 83 edges
7. `TicketEvent` - 83 edges
8. `Department` - 79 edges
9. `ChatSession` - 77 edges
10. `@inertiajs/vue3` - 72 edges

## Surprising Connections (you probably didn't know these)
- `{closure#21}()` --references_constant--> `TicketStatus`  [EXTRACTED]
  tests/Feature/DashboardTest.php → app/Enums/TicketStatus.php
- `{closure#1}()` --references_constant--> `TicketStatus`  [EXTRACTED]
  tests/Feature/Tickets/TicketClosingTest.php → app/Enums/TicketStatus.php
- `{closure#16}()` --references_constant--> `TicketStatus`  [EXTRACTED]
  tests/Feature/Tickets/TicketClosingTest.php → app/Enums/TicketStatus.php
- `{closure#18}()` --references_constant--> `TicketStatus`  [EXTRACTED]
  tests/Feature/Tickets/TicketClosingTest.php → app/Enums/TicketStatus.php
- `{closure#33}()` --references--> `TicketStatus`  [EXTRACTED]
  tests/Feature/Tickets/TicketClosingTest.php → app/Enums/TicketStatus.php

## Import Cycles
- None detected.

## Communities (197 total, 47 thin omitted)

### Community 0 - "Shared UI Components"
Cohesion: 0.02
Nodes (150): props, toast, dot, DashboardCount, DashboardFilterKey, dashboardFilterKeys, DashboardFilters, DashboardMetrics (+142 more)

### Community 1 - "Department & Form Inputs"
Cohesion: 0.02
Nodes (110): describedby, id, model, props, DepartmentEditTarget, DepartmentFilterKey, departmentFilterKeys, DepartmentFilterOptions (+102 more)

### Community 2 - "Chat Widget (Vue)"
Cohesion: 0.03
Nodes (105): axios, vue, canSend, draft, emit, input, Item, list (+97 more)

### Community 3 - "Inertia Pages & Roles"
Cohesion: 0.02
Nodes (77): useLeaveGuard(), CatalogGroup, CatalogPermission, MatrixView, RoleEditTarget, RoleFilterKey, roleFilterKeys, RoleFilters (+69 more)

### Community 4 - "Buttons & Branding"
Cohesion: 0.03
Nodes (64): @inertiajs/vue3, classes, inactive, props, sizes, variants, alignmentClasses, open (+56 more)

### Community 5 - "SLA & Timeline UI"
Cohesion: 0.04
Nodes (76): formatTarget(), parseTarget(), SlaPolicyDetail, SlaPolicyEditTarget, SlaPolicyEditValues, SlaPolicyFilterKey, slaPolicyFilterKeys, SlaPolicyFilterOptions (+68 more)

### Community 6 - "Auth Controllers"
Cohesion: 0.04
Nodes (18): AuthenticatedSessionController, ConfirmablePasswordController, EmailVerificationNotificationController, EmailVerificationPromptController, NewPasswordController, PasswordController, PasswordResetLinkController, RegisteredUserController (+10 more)

### Community 7 - "Login & Form Requests"
Cohesion: 0.04
Nodes (10): LoginRequest, StoreChatMessageRequest, NormalizesListingFilters, DashboardRequest, PerformanceRequest, ChangeTicketStatusRequest, CloseTicketRequest, ResolveTicketRequest (+2 more)

### Community 8 - "Chat Session Actions"
Cohesion: 0.04
Nodes (16): {closure#1}(), SendChatMessage, {closure#1}(), ChatSession, {closure#1}(), User, AuditLogPolicy, ChatSessionPolicy (+8 more)

### Community 9 - "Chat Cancel & Conflicts"
Cohesion: 0.06
Nodes (18): CancelChatSession, {closure#1}(), ChatSessionConflict, RequestChatSession, {closure#1}(), ChatAgentController, ChatMessageController, ChatPageController (+10 more)

### Community 10 - "Role Tests"
Cohesion: 0.05
Nodes (57): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#17}() (+49 more)

### Community 11 - "Badges & Justification Modal"
Cohesion: 0.04
Nodes (52): props, view, canConfirm, emit, props, submit(), text, emit (+44 more)

### Community 12 - "Category Actions"
Cohesion: 0.04
Nodes (12): CreateCategory, {closure#1}(), UpdateCategory, {closure#4}(), HasAuditLogs, TicketCategory, TicketCategoryPolicy, ChatSessionFactory (+4 more)

### Community 13 - "Ticket Status Actions"
Cohesion: 0.07
Nodes (10): ChangeTicketStatus, {closure#1}(), {closure#1}(), TicketAttachmentController, {closure#3}(), TicketController, TicketStatusController, ReopenTicketRequest (+2 more)

### Community 14 - "Authenticated Layout"
Cohesion: 0.04
Nodes (51): close(), isDesktop, menuOpen, onKeydown(), sidebar, toggleButton, CategoryEditTarget, CategoryFilterKey (+43 more)

### Community 15 - "Ticket Edit Tests"
Cohesion: 0.06
Nodes (51): {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}(), {closure#2}() (+43 more)

### Community 16 - "SLA State Enum"
Cohesion: 0.05
Nodes (32): SlaState, AtRisk, Met, None, OnTrack, Overdue, Paused, {closure#3}() (+24 more)

### Community 17 - "Action Exceptions"
Cohesion: 0.05
Nodes (22): CategoryEditConflict, AcceptNextChatSession, {closure#1}(), ChatAgentUnavailable, ChatCapacityReached, ChatQueueEmpty, LockAgentForAssignment, NoChatTechnicianAvailable (+14 more)

### Community 18 - "Chat Admin Tests"
Cohesion: 0.05
Nodes (31): {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}(), {closure#17}() (+23 more)

### Community 19 - "Role Actions & Controller"
Cohesion: 0.06
Nodes (17): CreateRole, UpdateRole, UpdateRolePermissions, {closure#3}(), RoleController, RolePermissionController, RoleIndexRequest, {closure#1}() (+9 more)

### Community 20 - "Attachments Config"
Cohesion: 0.08
Nodes (37): {closure#1}(), {closure#1}(), {closure#3}(), AttachmentInspector, {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}() (+29 more)

### Community 21 - "Role Permission Matrix Tests"
Cohesion: 0.07
Nodes (36): {closure#12}(), {closure#17}(), {closure#19}(), {closure#20}(), {closure#21}(), {closure#22}(), {closure#23}(), {closure#24}() (+28 more)

### Community 22 - "User Invitation Notification"
Cohesion: 0.06
Nodes (31): UserInvitation, {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}() (+23 more)

### Community 23 - "Ticket Pending Tests"
Cohesion: 0.06
Nodes (40): {closure#1}(), {closure#10}(), {closure#11}(), {closure#14}(), {closure#15}(), {closure#17}(), {closure#18}(), {closure#2}() (+32 more)

### Community 24 - "Alert & Modal"
Cohesion: 0.05
Nodes (46): config, current, props, close(), closeOnEscape(), dialog, emit, maxWidthClass (+38 more)

### Community 25 - "Bar Charts"
Cohesion: 0.06
Nodes (39): max, props, rows, percent(), PerformanceAverage, PerformanceEvolutionPoint, PerformanceFilterKey, performanceFilterKeys (+31 more)

### Community 26 - "Ticket List Filters"
Cohesion: 0.05
Nodes (48): TicketFilterOptions, TicketListItem, applyStatus(), applyModule(), applySelect(), apply(), refresh(), apply() (+40 more)

### Community 27 - "Permission Catalog Tests"
Cohesion: 0.05
Nodes (29): catalogProps(), catalogViewerWithOnly(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#15}(), {closure#17}(), {closure#19}() (+21 more)

### Community 28 - "Comment & Attachment Actions"
Cohesion: 0.06
Nodes (14): EditTicketComment, {closure#2}(), StoreTicketAttachments, CommentVisibility, Internal, Public, {closure#1}(), TicketCommentController (+6 more)

### Community 29 - "Ticket Status Enum"
Cohesion: 0.07
Nodes (39): TicketStatus, AwaitingRequester, Closed, InProgress, Open, Pending, PendingDepartments, PendingThirdParty (+31 more)

### Community 30 - "NPM Dependencies"
Cohesion: 0.06
Nodes (38): devDependencies, autoprefixer@^10.4.12, axios@^1.11.0, concurrently@^9.0.1, @inertiajs/vue3@^2.0.0, laravel-echo@^2.6.3, @laravel/echo-vue@^2.6.3, laravel-vite-plugin@^2.0.0 (+30 more)

### Community 31 - "Ticket Reopening Tests"
Cohesion: 0.08
Nodes (42): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}() (+34 more)

### Community 32 - "Resolve & Update Ticket"
Cohesion: 0.11
Nodes (16): {closure#1}(), {closure#1}(), UpdateTicketAttributes, SlaClock, {closure#1}(), {closure#1}(), {closure#2}(), {closure#1}() (+8 more)

### Community 33 - "User Edit Tests"
Cohesion: 0.10
Nodes (36): {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}(), {closure#17}() (+28 more)

### Community 34 - "Assign Ticket & Roles"
Cohesion: 0.06
Nodes (12): AssignTicket, CloseTicket, {closure#1}(), {closure#1}(), RegisterTicketPending, {closure#1}(), ReopenTicket, ResolveTicket (+4 more)

### Community 35 - "Audit Coverage Ledger Script"
Cohesion: 0.12
Nodes (41): ATTEMPT_FIELDS, ATTEMPT_STATUSES, canonicalCoverageId(), collectUnitErrors(), createErrorList(), encodeCanonicalRef(), escapeUnsafeDiagnosticCharacters(), fs (+33 more)

### Community 36 - "Ticket Closing Tests"
Cohesion: 0.09
Nodes (37): closedEvents(), closerWithPermissions(), closingUrl(), {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}() (+29 more)

### Community 37 - "Findings Validator Script"
Cohesion: 0.10
Nodes (38): canonicalKey(), append(), encode(), codePointLength(), collect(), collectDataLimitErrors(), collectFindingSemanticErrors(), collectSchemaErrors() (+30 more)

### Community 38 - "Performance Tests"
Cohesion: 0.13
Nodes (35): {closure#10}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#16}(), {closure#17}(), {closure#18}(), {closure#19}() (+27 more)

### Community 39 - "Category Edit Tests"
Cohesion: 0.10
Nodes (31): categoryEditData(), categoryEditorWithOnly(), {closure#12}(), {closure#14}(), {closure#15}(), {closure#16}(), {closure#17}(), {closure#18}() (+23 more)

### Community 40 - "Community 40"
Cohesion: 0.07
Nodes (24): {closure#10}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#16}(), {closure#18}(), {closure#21}(), {closure#24}() (+16 more)

### Community 41 - "Community 41"
Cohesion: 0.10
Nodes (30): auditDetail(), auditEvents(), auditFixture(), auditorWithPermissions(), auditUrl(), {closure#10}(), {closure#11}(), {closure#12}() (+22 more)

### Community 42 - "Community 42"
Cohesion: 0.08
Nodes (29): {closure#10}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}(), {closure#17}(), {closure#18}(), {closure#19}() (+21 more)

### Community 43 - "Community 43"
Cohesion: 0.07
Nodes (11): UpdateChatCapacity, ChatCapacityController, EnsureChatEnabled, UpdateChatCapacityRequest, {closure#1}(), ValidAttachment, ChatTransaction, {closure#1}() (+3 more)

### Community 44 - "Community 44"
Cohesion: 0.08
Nodes (12): CreateUser, {closure#10}(), {closure#11}(), {closure#3}(), {closure#4}(), {closure#6}(), {closure#7}(), {closure#9}() (+4 more)

### Community 45 - "Community 45"
Cohesion: 0.08
Nodes (23): {closure#1}(), {closure#2}(), {closure#2}(), {closure#1}(), {closure#2}(), {closure#1}(), {closure#2}(), {closure#4}() (+15 more)

### Community 46 - "Community 46"
Cohesion: 0.07
Nodes (10): SlaPolicyController, {closure#1}(), {closure#1}(), StoreChatSessionRequest, SlaPolicyIndexRequest, {closure#1}(), StoreSlaPolicyRequest, {closure#1}() (+2 more)

### Community 47 - "Community 47"
Cohesion: 0.06
Nodes (36): model, id, model, TicketTechnician, categoryId, close(), dirty, emit (+28 more)

### Community 48 - "Community 48"
Cohesion: 0.07
Nodes (26): categoryListingProps(), categoryOperatorWithOnly(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}() (+18 more)

### Community 49 - "Community 49"
Cohesion: 0.08
Nodes (7): {closure#7}(), {closure#8}(), ChatSessionEvent, TicketAttachment, TicketCommentVersion, TicketSlaPause, {closure#22}()

### Community 50 - "Community 50"
Cohesion: 0.09
Nodes (28): {closure#12}(), {closure#14}(), {closure#16}(), {closure#17}(), {closure#18}(), {closure#19}(), {closure#2}(), {closure#20}() (+20 more)

### Community 51 - "Community 51"
Cohesion: 0.11
Nodes (33): {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}(), {closure#17}() (+25 more)

### Community 52 - "Community 52"
Cohesion: 0.10
Nodes (20): ChatAvailability, Available, Busy, Unavailable, activeSessionsFor(), availabilityOf(), {closure#10}(), {closure#11}() (+12 more)

### Community 53 - "Community 53"
Cohesion: 0.08
Nodes (27): accept, emit, hint, input, onPick(), props, remove(), toast (+19 more)

### Community 54 - "Community 54"
Cohesion: 0.11
Nodes (31): chatRequester(), {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#15}(), {closure#16}() (+23 more)

### Community 55 - "Community 55"
Cohesion: 0.12
Nodes (26): {closure#11}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}(), {closure#17}(), {closure#18}(), {closure#19}() (+18 more)

### Community 56 - "Community 56"
Cohesion: 0.09
Nodes (23): assigneeUrl(), assignTicket(), {closure#10}(), {closure#15}(), {closure#16}(), {closure#17}(), {closure#18}(), {closure#2}() (+15 more)

### Community 57 - "Community 57"
Cohesion: 0.08
Nodes (20): {closure#1}(), TicketTransitionFlow, Closing, Pending, Reopening, Resolution, StatusChange, {closure#10}() (+12 more)

### Community 58 - "Community 58"
Cohesion: 0.08
Nodes (20): {closure#10}(), {closure#11}(), {closure#14}(), {closure#16}(), {closure#19}(), {closure#20}(), {closure#21}(), {closure#23}() (+12 more)

### Community 59 - "Community 59"
Cohesion: 0.09
Nodes (25): {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#15}(), {closure#16}(), {closure#17}(), {closure#19}() (+17 more)

### Community 60 - "Community 60"
Cohesion: 0.10
Nodes (22): {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}(), {closure#17}() (+14 more)

### Community 61 - "Community 61"
Cohesion: 0.14
Nodes (24): closeChat(), closePayload(), {closure#10}(), {closure#11}(), {closure#13}(), {closure#15}(), {closure#16}(), {closure#17}() (+16 more)

### Community 62 - "Community 62"
Cohesion: 0.10
Nodes (29): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}() (+21 more)

### Community 63 - "Community 63"
Cohesion: 0.08
Nodes (10): CreateDepartment, {closure#1}(), UpdateDepartment, {closure#11}(), {closure#14}(), {closure#16}(), {closure#5}(), TicketIndexRequest (+2 more)

### Community 64 - "Community 64"
Cohesion: 0.15
Nodes (22): {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}(), resolvedTicket(), runAutoClose(), breachIndicators() (+14 more)

### Community 65 - "Community 65"
Cohesion: 0.10
Nodes (26): categoryCreatorWithOnly(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}() (+18 more)

### Community 66 - "Community 66"
Cohesion: 0.09
Nodes (20): {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#16}(), {closure#17}(), {closure#18}(), {closure#20}() (+12 more)

### Community 67 - "Community 67"
Cohesion: 0.09
Nodes (17): {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#17}(), {closure#18}() (+9 more)

### Community 68 - "Community 68"
Cohesion: 0.11
Nodes (28): {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}(), {closure#17}() (+20 more)

### Community 69 - "Community 69"
Cohesion: 0.09
Nodes (30): environment, volumes, APP_DEBUG, APP_ENV, LOG_CHANNEL, VITE_DOCKER, environment, volumes (+22 more)

### Community 70 - "Community 70"
Cohesion: 0.10
Nodes (28): Direction, ListingOptions, useListing(), PermissionDetail, PermissionFilterKey, permissionFilterKeys, PermissionFilterOptions, PermissionFilters (+20 more)

### Community 71 - "Community 71"
Cohesion: 0.10
Nodes (27): AuditChange, AuditDetail, AuditEntity, AuditFilterKey, auditFilterKeys, AuditFilterOptions, AuditFilters, AuditListItem (+19 more)

### Community 72 - "Community 72"
Cohesion: 0.11
Nodes (23): {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}(), {closure#17}() (+15 more)

### Community 73 - "Community 73"
Cohesion: 0.12
Nodes (9): {closure#1}(), EndChatSession, {closure#1}(), OpenTicket, TicketNumberUnavailable, ChatOutcome, Resolved, Unresolved (+1 more)

### Community 74 - "Community 74"
Cohesion: 0.11
Nodes (6): CategoryController, {closure#3}(), {closure#7}(), CategoryIndexRequest, StoreCategoryRequest, UpdateCategoryRequest

### Community 75 - "Community 75"
Cohesion: 0.12
Nodes (20): {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#16}(), {closure#17}(), {closure#18}() (+12 more)

### Community 76 - "Community 76"
Cohesion: 0.10
Nodes (9): {closure#1}(), {closure#8}(), DashboardController, {closure#1}(), {closure#4}(), {closure#5}(), ChatMetrics, {closure#23}() (+1 more)

### Community 77 - "Community 77"
Cohesion: 0.13
Nodes (16): {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}(), {closure#17}(), {closure#2}() (+8 more)

### Community 78 - "Community 78"
Cohesion: 0.08
Nodes (15): input, model, input, model, visible, initials, user, currentInput (+7 more)

### Community 79 - "Community 79"
Cohesion: 0.08
Nodes (12): {closure#11}(), {closure#13}(), {closure#15}(), {closure#16}(), {closure#17}(), {closure#20}(), {closure#21}(), {closure#22}() (+4 more)

### Community 80 - "Community 80"
Cohesion: 0.13
Nodes (19): page, sections, d, IconName, paths, props, items, page (+11 more)

### Community 81 - "Community 81"
Cohesion: 0.14
Nodes (18): {closure#10}(), {closure#11}(), {closure#12}(), {closure#14}(), {closure#16}(), {closure#17}(), {closure#18}(), {closure#19}() (+10 more)

### Community 82 - "Community 82"
Cohesion: 0.10
Nodes (13): TicketPriority, Critical, High, Low, Medium, {closure#13}(), {closure#14}(), {closure#3}() (+5 more)

### Community 83 - "Community 83"
Cohesion: 0.11
Nodes (7): {closure#3}(), {closure#5}(), {closure#7}(), DepartmentController, DepartmentIndexRequest, StoreDepartmentRequest, UpdateDepartmentRequest

### Community 84 - "Community 84"
Cohesion: 0.10
Nodes (9): SelfAssignTicket, ChatMetricsController, TicketAssignmentController, TicketExportController, TicketPendingController, TicketSelfAssignmentController, {closure#1}(), {closure#2}() (+1 more)

### Community 85 - "Community 85"
Cohesion: 0.13
Nodes (19): {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}(), {closure#17}() (+11 more)

### Community 86 - "Community 86"
Cohesion: 0.12
Nodes (16): assert, confirmed(), errorsFor(), evidence(), fs, needsValidation(), os, path (+8 more)

### Community 87 - "Community 87"
Cohesion: 0.09
Nodes (22): AdminEvent, CategoryActivated, CategoryCreated, CategoryDeactivated, CategoryUpdated, ChatCapacityUpdated, DepartmentActivated, DepartmentCreated (+14 more)

### Community 88 - "Community 88"
Cohesion: 0.15
Nodes (19): {closure#29}(), {closure#26}(), {closure#29}(), {closure#31}(), {closure#38}(), {closure#31}(), {closure#30}(), {closure#1}() (+11 more)

### Community 89 - "Community 89"
Cohesion: 0.15
Nodes (13): authorizeChannel(), {closure#14}(), {closure#16}(), {closure#18}(), {closure#2}(), {closure#20}(), {closure#3}(), {closure#4}() (+5 more)

### Community 90 - "Community 90"
Cohesion: 0.18
Nodes (19): changePriorityAt(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}() (+11 more)

### Community 91 - "Community 91"
Cohesion: 0.09
Nodes (5): {closure#10}(), {closure#15}(), {closure#16}(), {closure#17}(), {closure#18}()

### Community 92 - "Community 92"
Cohesion: 0.12
Nodes (10): boot(), {closure#1}(), {closure#4}(), {closure#5}(), config(), __construct(), expectations(), isInstalled() (+2 more)

### Community 93 - "Community 93"
Cohesion: 0.10
Nodes (19): TicketEvent, Assigned, AttachmentAdded, CategoryChanged, Closed, CommentAdded, CommentEdited, Created (+11 more)

### Community 94 - "Community 94"
Cohesion: 0.11
Nodes (13): LIMITS, archivedAttempt(), assert, fs, localCheck(), os, path, sourceCheck() (+5 more)

### Community 95 - "Community 95"
Cohesion: 0.12
Nodes (10): auditOperatorWithOnly(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#15}(), {closure#4}(), {closure#40}(), {closure#6}() (+2 more)

### Community 96 - "Community 96"
Cohesion: 0.17
Nodes (15): {closure#10}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#16}(), {closure#17}(), {closure#18}(), {closure#2}() (+7 more)

### Community 97 - "Community 97"
Cohesion: 0.15
Nodes (5): AuditController, {closure#1}(), {closure#14}(), {closure#2}(), AuditIndexRequest

### Community 98 - "Community 98"
Cohesion: 0.16
Nodes (6): CreateSlaPolicy, {closure#1}(), UpdateSlaPolicy, SlaPolicy, SlaPolicyPolicy, __invoke()

### Community 99 - "Community 99"
Cohesion: 0.17
Nodes (6): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), TicketAuditController, TicketHistory

### Community 100 - "Community 100"
Cohesion: 0.14
Nodes (7): ChatEvent, Accepted, Cancelled, Ended, Requested, Transferred, ChatSessionChanged

### Community 101 - "Community 101"
Cohesion: 0.15
Nodes (9): ChatSessionStatus, Active, Cancelled, Ended, Waiting, {closure#8}(), {closure#1}(), {closure#2}() (+1 more)

### Community 102 - "Community 102"
Cohesion: 0.12
Nodes (7): {closure#10}(), {closure#11}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}(), {closure#9}()

### Community 103 - "Community 103"
Cohesion: 0.16
Nodes (7): {closure#10}(), {closure#11}(), {closure#2}(), {closure#4}(), {closure#5}(), {closure#6}(), transfer()

### Community 104 - "Community 104"
Cohesion: 0.17
Nodes (7): {closure#1}(), {closure#2}(), {closure#3}(), {closure#1}(), {closure#2}(), {closure#1}(), {closure#2}()

### Community 105 - "Community 105"
Cohesion: 0.15
Nodes (9): {closure#11}(), {closure#13}(), {closure#15}(), {closure#2}(), {closure#3}(), {closure#5}(), {closure#7}(), {closure#9}() (+1 more)

### Community 107 - "Community 107"
Cohesion: 0.14
Nodes (7): {closure#1}(), {closure#2}(), {closure#1}(), {closure#2}(), TicketExportRequest, {closure#2}(), TicketListingQuery

### Community 108 - "Community 108"
Cohesion: 0.12
Nodes (10): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}() (+2 more)

### Community 109 - "Community 109"
Cohesion: 0.15
Nodes (4): chatMessage(), {closure#13}(), {closure#6}(), {closure#7}()

### Community 110 - "Community 110"
Cohesion: 0.27
Nodes (14): {closure#10}(), {closure#11}(), {closure#12}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}() (+6 more)

### Community 111 - "Community 111"
Cohesion: 0.30
Nodes (14): {closure#10}(), {closure#11}(), {closure#12}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}() (+6 more)

### Community 112 - "Community 112"
Cohesion: 0.12
Nodes (15): compilerOptions, allowJs, esModuleInterop, forceConsistentCasingInFileNames, isolatedModules, jsx, module, moduleResolution (+7 more)

### Community 114 - "Community 114"
Cohesion: 0.13
Nodes (15): depends_on, env_file, image, ports, restart, volumes, condition, db (+7 more)

### Community 116 - "Community 116"
Cohesion: 0.23
Nodes (3): {closure#1}(), {closure#1}(), ChatAgent

### Community 117 - "Community 117"
Cohesion: 0.20
Nodes (6): RegisterTicketPendingRequest, {closure#13}(), {closure#8}(), {closure#9}(), {closure#8}(), {closure#14}()

### Community 118 - "Community 118"
Cohesion: 0.20
Nodes (5): ChatMessageSent, {closure#1}(), {closure#1}(), ChatMessage, {closure#3}()

### Community 119 - "Community 119"
Cohesion: 0.21
Nodes (6): {closure#1}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), HandleInertiaRequests

### Community 120 - "Community 120"
Cohesion: 0.21
Nodes (3): AutoCloseTicket, ChatHealth, CloseResolvedTickets

### Community 121 - "Community 121"
Cohesion: 0.26
Nodes (5): {closure#2}(), {closure#3}(), {closure#6}(), Permission, {closure#2}()

### Community 122 - "Community 122"
Cohesion: 0.15
Nodes (3): {closure#1}(), {closure#1}(), {closure#1}()

### Community 124 - "Community 124"
Cohesion: 0.15
Nodes (13): auditProps(), {closure#16}(), {closure#17}(), {closure#18}(), {closure#19}(), {closure#20}(), {closure#22}(), {closure#24}() (+5 more)

### Community 125 - "Community 125"
Cohesion: 0.29
Nodes (11): capacityOf(), {closure#10}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}() (+3 more)

### Community 126 - "Community 126"
Cohesion: 0.18
Nodes (7): {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), RecordSlaBreaches

### Community 127 - "Community 127"
Cohesion: 0.17
Nodes (4): {closure#1}(), {closure#10}(), {closure#6}(), {closure#9}()

### Community 128 - "Community 128"
Cohesion: 0.20
Nodes (4): AppServiceProvider, {closure#1}(), {closure#2}(), {closure#3}()

### Community 129 - "Community 129"
Cohesion: 0.29
Nodes (12): build, VITE_REVERB_APP_KEY, VITE_REVERB_HOST, VITE_REVERB_PORT, VITE_REVERB_SCHEME, args, context, target (+4 more)

### Community 130 - "Community 130"
Cohesion: 0.17
Nodes (12): auditEvent(), {closure#21}(), {closure#23}(), {closure#25}(), {closure#3}(), {closure#30}(), {closure#33}(), {closure#35}() (+4 more)

### Community 131 - "Community 131"
Cohesion: 0.21
Nodes (5): {closure#2}(), {closure#3}(), v18Download(), v18Pdf(), v18Props()

### Community 132 - "Community 132"
Cohesion: 0.40
Nodes (11): JsonStructureError, preflightJsonText(), beginContainer(), beginString(), beginValue(), closeContainer(), countValue(), currentContainer() (+3 more)

### Community 135 - "Community 135"
Cohesion: 0.20
Nodes (10): require-dev, fakerphp/faker, laravel/breeze, laravel/pail, laravel/pint, laravel/sail, mockery/mockery, nunomaduro/collision (+2 more)

### Community 137 - "Community 137"
Cohesion: 0.20
Nodes (9): env_file, image, restart, volumes, services, db, volumes, dbdata (+1 more)

### Community 138 - "Community 138"
Cohesion: 0.20
Nodes (10): command, depends_on, entrypoint, env_file, image, ports, restart, user (+2 more)

### Community 139 - "Community 139"
Cohesion: 0.24
Nodes (3): chatPermissionsOf(), {closure#1}(), {closure#5}()

### Community 140 - "Community 140"
Cohesion: 0.36
Nodes (8): chatAllows(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}()

### Community 141 - "Community 141"
Cohesion: 0.22
Nodes (8): description, keywords, license, minimum-stability, name, prefer-stable, $schema, type

### Community 142 - "Community 142"
Cohesion: 0.22
Nodes (9): scripts, dev, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, pre-package-uninstall, setup (+1 more)

### Community 143 - "Community 143"
Cohesion: 0.22
Nodes (9): command, depends_on, entrypoint, env_file, image, restart, user, volumes (+1 more)

### Community 144 - "Community 144"
Cohesion: 0.22
Nodes (9): command, depends_on, entrypoint, env_file, image, restart, user, volumes (+1 more)

### Community 145 - "Community 145"
Cohesion: 0.25
Nodes (9): {closure#11}(), {closure#12}(), {closure#13}(), {closure#20}(), {closure#7}(), {closure#9}(), exportedNumbers(), exportScopedAdmin() (+1 more)

### Community 147 - "Community 147"
Cohesion: 0.25
Nodes (8): require, inertiajs/inertia-laravel, laravel/framework, laravel/reverb, laravel/sanctum, laravel/tinker, php, tightenco/ziggy

### Community 149 - "Community 149"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 152 - "Community 152"
Cohesion: 0.40
Nodes (3): TicketCsvExport, {closure#28}(), {closure#29}()

### Community 153 - "Community 153"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 154 - "Community 154"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 155 - "Community 155"
Cohesion: 0.53
Nodes (6): healthcheck, interval, retries, test, timeout, healthcheck

### Community 157 - "Community 157"
Cohesion: 0.33
Nodes (6): {closure#19}(), {closure#23}(), {closure#25}(), {closure#26}(), {closure#30}(), csvRows()

### Community 158 - "Community 158"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 175 - "Community 175"
Cohesion: 0.50
Nodes (3): PageProps, Paginator, User

### Community 177 - "Community 177"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 178 - "Community 178"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

## Knowledge Gaps
- **76 isolated node(s):** `UserCreated`, `UserUpdated`, `UserDeactivated`, `UserActivated`, `DepartmentCreated` (+71 more)
  These have ≤1 connection - possible missing edges. (Counts symbols only; 1833 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **47 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `Chat Session Actions` to `Auth Controllers`, `Login & Form Requests`, `Chat Cancel & Conflicts`, `Role Tests`, `Category Actions`, `Ticket Status Actions`, `Ticket Edit Tests`, `SLA State Enum`, `Action Exceptions`, `Chat Admin Tests`, `Role Actions & Controller`, `Attachments Config`, `Role Permission Matrix Tests`, `User Invitation Notification`, `Ticket Pending Tests`, `Permission Catalog Tests`, `Comment & Attachment Actions`, `Ticket Status Enum`, `Ticket Reopening Tests`, `Resolve & Update Ticket`, `User Edit Tests`, `Assign Ticket & Roles`, `Ticket Closing Tests`, `Performance Tests`, `Category Edit Tests`, `Community 40`, `Community 41`, `Community 42`, `Community 43`, `Community 44`, `Community 45`, `Community 48`, `Community 49`, `Community 50`, `Community 51`, `Community 52`, `Community 54`, `Community 55`, `Community 56`, `Community 58`, `Community 59`, `Community 60`, `Community 61`, `Community 62`, `Community 63`, `Community 64`, `Community 65`, `Community 66`, `Community 67`, `Community 68`, `Community 72`, `Community 73`, `Community 75`, `Community 76`, `Community 77`, `Community 79`, `Community 81`, `Community 84`, `Community 85`, `Community 88`, `Community 89`, `Community 90`, `Community 91`, `Community 95`, `Community 96`, `Community 98`, `Community 102`, `Community 103`, `Community 105`, `Community 107`, `Community 108`, `Community 109`, `Community 110`, `Community 111`, `Community 115`, `Community 116`, `Community 125`, `Community 128`, `Community 130`, `Community 131`, `Community 134`, `Community 136`, `Community 139`, `Community 140`, `Community 145`, `Community 146`, `Community 148`, `Community 151`, `Community 156`, `Community 164`?**
  _High betweenness centrality (0.131) - this node is a cross-community bridge._
- **What connects `UserCreated`, `UserUpdated`, `UserDeactivated` to the rest of the system?**
  _76 weakly-connected nodes found - possible missing edges._
- **Should `Shared UI Components` be split into smaller, more focused modules?**
  _Cohesion score 0.018869178127699157 - nodes in this community are weakly interconnected._
- **Why does `Ticket` connect `Ticket Status Actions` to `Community 131`, `Auth Controllers`, `Login & Form Requests`, `Chat Session Actions`, `Chat Cancel & Conflicts`, `Category Actions`, `Ticket Edit Tests`, `SLA State Enum`, `Chat Admin Tests`, `Attachments Config`, `Ticket Pending Tests`, `Comment & Attachment Actions`, `Ticket Status Enum`, `Ticket Reopening Tests`, `Resolve & Update Ticket`, `User Edit Tests`, `Assign Ticket & Roles`, `Ticket Closing Tests`, `Performance Tests`, `Category Edit Tests`, `Community 40`, `Community 41`, `Community 42`, `Community 43`, `Community 45`, `Community 48`, `Community 49`, `Community 50`, `Community 51`, `Community 55`, `Community 56`, `Community 59`, `Community 61`, `Community 62`, `Community 63`, `Community 64`, `Community 65`, `Community 66`, `Community 68`, `Community 72`, `Community 73`, `Community 75`, `Community 76`, `Community 77`, `Community 79`, `Community 81`, `Community 84`, `Community 85`, `Community 88`, `Community 90`, `Community 91`, `Community 99`, `Community 105`, `Community 107`, `Community 108`, `Community 109`, `Community 110`, `Community 111`, `Community 113`, `Community 117`, `Community 120`, `Community 126`?**
  _High betweenness centrality (0.079) - this node is a cross-community bridge._
- **Should `Department & Form Inputs` be split into smaller, more focused modules?**
  _Cohesion score 0.022996130458817026 - nodes in this community are weakly interconnected._
- **Why does `TicketStatus` connect `Ticket Status Enum` to `Community 131`, `Login & Form Requests`, `Category Actions`, `Ticket Status Actions`, `Ticket Edit Tests`, `SLA State Enum`, `Attachments Config`, `Ticket Pending Tests`, `Ticket Reopening Tests`, `Resolve & Update Ticket`, `Assign Ticket & Roles`, `Ticket Closing Tests`, `Performance Tests`, `Community 41`, `Community 42`, `Community 45`, `Community 49`, `Community 51`, `Community 56`, `Community 57`, `Community 59`, `Community 62`, `Community 63`, `Community 64`, `Community 68`, `Community 73`, `Community 75`, `Community 76`, `Community 84`, `Community 88`, `Community 90`, `Community 91`, `Community 107`, `Community 108`, `Community 110`, `Community 111`, `Community 117`, `Community 120`, `Community 126`?**
  _High betweenness centrality (0.035) - this node is a cross-community bridge._
- **Should `Chat Widget (Vue)` be split into smaller, more focused modules?**
  _Cohesion score 0.026259679048367186 - nodes in this community are weakly interconnected._