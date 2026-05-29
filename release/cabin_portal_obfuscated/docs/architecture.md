# Architecture

## Application Components

```mermaid
flowchart TD
    subgraph Client ["Client Viewport"]
        Passenger["Passenger browser"]
        Staff["Staff browser"]
    end

    subgraph Security ["Security & Routing Gateways"]
        Proxy["public_api.php (Public API Proxy)"]
        Htaccess[".htaccess (Access Hardening)"]
    end

    subgraph Controllers ["Controllers & Logic"]
        Index["index.php (Passenger Portal)"]
        StaffUI["staff.php (Staff Dashboard)"]
        API["api.php (Core REST API)"]
        Mailer["mailer.php (Modular Mailer)"]
    end

    subgraph Core ["System Initialization"]
        Bootstrap["bootstrap.php (Bootloader)"]
        BackupLock["uploads/.last_backup_check"]
    end

    subgraph Storage ["Station SQLite Databases"]
        SQLite["cabin_db_CODE.sqlite (Active DB)"]
        BackupSQL["cabin_db_backup_CODE.sqlite (Local Backups)"]
    end

    %% Client Routing
    Passenger --> Htaccess
    Staff --> Htaccess
    Htaccess --> Index
    Htaccess --> StaffUI
    
    %% Request Delegation & Proxies
    Index --> Proxy
    Proxy -->|Defines Internal Call| API
    StaffUI -->|Session Auth Checks| API
    
    %% Core Bootstrapping
    Index --> Bootstrap
    StaffUI --> Bootstrap
    API --> Bootstrap
    
    %% Storage & Automated Backups
    Bootstrap --> SQLite
    Bootstrap -->|Rate-Limited Lock-File Check| BackupLock
    BackupLock -->|Auto Backup Trigger| BackupSQL
    
    %% Mail Operations
    API --> Mailer
    StaffUI --> Mailer
```

## Request Flow & Security Boundaries

```mermaid
sequenceDiagram
    participant P as Passenger
    participant UI as index.php
    participant Proxy as public_api.php
    participant API as api.php
    participant Boot as bootstrap.php
    participant Lock as Backup Check Lock
    participant DB as SQLite (cabin_db_CODE.sqlite)

    P->>UI: Search found items or submit lost report
    UI->>Proxy: JSON POST / AJAX Action with CSRF token
    Proxy->>Proxy: Validate Same-Site CSRF & Allowed Actions
    Proxy->>API: Safe delegate execution (defines AF_INTERNAL_API_CALL)
    API->>Boot: Resolve active station from session/cookie/headers
    Boot->>Lock: Check lock file time (rate-limited check once per hour)
    alt Lock age > 3600s or first check
        Boot->>DB: Perform station-isolated copy to cabin_db_backup_CODE.sqlite
        Boot->>DB: Save last_backup_time in settings table
    end
    Boot->>DB: Provision schema if missing
    API->>DB: Read or write item/report records
    API-->>Proxy: JSON Response
    Proxy-->>UI: Sanitized JSON payload
    UI-->>P: Updated viewport view
```

## Station Resolution Flow

```mermaid
flowchart TD
    Start["Incoming request"] --> StaffPinned{"Staff session has active station?"}
    StaffPinned -->|Yes| Station["Use staff station"]
    StaffPinned -->|No| Param{"station in GET or POST?"}
    Param -->|Yes| ValidateParam["Validate against stations.json"]
    ValidateParam --> Station
    Param -->|No| Session{"active_station in session?"}
    Session -->|Yes| Station
    Session -->|No| Cookie{"af_station cookie?"}
    Cookie -->|Yes| Station
    Cookie -->|No| Header{"X-Station header?"}
    Header -->|Yes| Station
    Header -->|No| Default["Use first station, fallback MUC"]
    Default --> Station
    Station --> DBFile["cabin_db.sqlite or cabin_db_CODE.sqlite"]
```

## Data Model

```mermaid
erDiagram
    settings {
        text key PK
        text value
    }

    items {
        text tag_no PK
        text item_description
        text contents
        text pax_name
        text pax_email
        text status
        text photo
        datetime created_at
    }

    pending_reports {
        integer id PK
        text report_ref
        text tag_no
        text airline
        text pax_name
        text pax_email
        text status
        text matched_tag_no
        datetime created_at
        datetime reviewed_at
    }

    airlines {
        integer id PK
        text name
        text code
        text logo
        text domain
    }

    deleted_items {
        text tag_no PK
    }

    deleted_airlines {
        text code PK
    }
```

## Runtime Files & Storage Layout

- `cabin_db.sqlite` / `cabin_db_CODE.sqlite`: Active station database files (ignored by Git).
- `cabin_db_backup_CODE.sqlite`: Station-specific automated backup files, created via async background runners (ignored by Git).
- `uploads/`: Stores runtime attachments, image media, and local debug email copies.
- `uploads/.last_backup_check`: Lock-file to control the I/O rate-limiting of background backup checking (ensures runs occur at most once per hour).
- `release/`: Target directory for generated and compiled production releases. The obfuscated package is committed for automated updater installs; local secrets inside release output remain ignored.
- `uploads/.htaccess`: Security constraint file blocking code execution inside uploads directories.
