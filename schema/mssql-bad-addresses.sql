-- MSSQL schema for syncing confirmed hard-bounce bad addresses from Bounce Monitor.
-- Run this script in your MSSQL database to create the table, then set the same
-- table name in the Control Panel → Remote MSSQL Sync.
--
-- Column meanings:
--   email        : Bad email address (original_to from hard bounces). Primary key; no duplicates.
--   last_updated : When this record was last updated (UTC recommended).
--   reason       : Human-readable reason (e.g. SMTP code and description).
--
-- If the table already exists with different column names, either rename columns
-- to match (email, last_updated, reason) or adjust the application to match your schema.

-- Default table name used by the app if not overridden in settings: BadAddresses
-- You can change the table name here and set the same name in Control Panel.

IF OBJECT_ID(N'dbo.BadAddresses', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.BadAddresses (
        email        NVARCHAR(255)  NOT NULL,
        last_updated DATETIME2      NOT NULL,
        reason       NVARCHAR(1000)  NULL,
        CONSTRAINT PK_BadAddresses PRIMARY KEY CLUSTERED (email)
    );

    -- Optional: index for querying by last_updated
    CREATE NONCLUSTERED INDEX IX_BadAddresses_last_updated ON dbo.BadAddresses (last_updated DESC);
END
GO
