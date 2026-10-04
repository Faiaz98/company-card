# Company Card

An internal company payment-card system built with React, TypeScript, PHP, and MySQL.

Employees receive a company-issued card connected to an internal wallet. Approved merchants can use the Company Card API to charge cards and issue refunds without accessing the internal wallet or employee data directly.

## Architecture

```text
                         COMPANY CARD API
                                |
              +-----------------+-----------------+
              |                 |                 |
          Employees          Wallets          Merchants
              |                 |                 |
            Cards           Ledger           API Keys
                                |
                         Payment / Refund API
                                |
                    +-----------+-----------+
                    |           |           |
                  Shop A      Shop B      Shop C
                  Own DB     Own DB     Own DB
```

The Company Card platform owns employee accounts, cards, wallets, transactions, and the financial ledger.

Merchants remain separate systems and communicate with the platform through authenticated REST APIs.

## Tech Stack

### Frontend

- React
- TypeScript
- Vite
- REST API integration

### Backend

- PHP
- PDO
- REST-style HTTP endpoints
- PHP sessions
- MySQL

### Security & Reliability

- Password hashing
- Role-based authorization
- CSRF protection
- Hashed card credentials
- Hashed merchant API keys
- Prepared SQL statements
- Rate limiting
- Idempotency keys
- Database transactions
- Row-level locking
- Integer-based money calculations
- Immutable ledger entries
- Audit logging

## Core Features

### Employee Management

Administrators can:

- Create employees
- Assign employee codes
- Create employee accounts
- Create employee wallets
- View employee balances

Employees can access their own account and card information.

### Card Management

Cards support a lifecycle:

```text
ACTIVE
  |
  +----> SUSPENDED
  |
  +----> REVOKED

ACTIVE
  |
  +----> REPLACED
```

Each card has:

- A unique card reference
- A unique secret credential
- A lifecycle status
- An issuing timestamp
- Optional revocation information

The raw card credential is only returned when the card is created or replaced.

The database stores only its SHA-256 hash.

### Wallets

Each employee has one wallet.

Wallet balances are stored as:

```text
DECIMAL(15,2)
```

Financial calculations are performed using integer cents internally rather than floating-point arithmetic.

For example:

```text
৳100.50
   ↓
10050 cents
```

This avoids floating-point precision problems when performing financial calculations.

### Immutable Ledger

Every wallet movement creates a ledger entry.

Examples:

```text
TOP_UP
  → CREDIT

PURCHASE
  → DEBIT

REFUND
  → CREDIT
```

The wallet balance is the current state.

The ledger provides the historical record of how that balance changed.

### Merchant Authentication

Merchants authenticate using API keys:

```http
Authorization: Bearer <merchant-api-key>
```

The raw API key is never stored in the database.

Instead:

```text
API key
   ↓
SHA-256
   ↓
stored hash
```

The merchant only receives the raw credential when it is generated.

### Card Payments

A merchant can charge a company card through the payment API.

Conceptually:

```text
Merchant
   |
   | card credential + amount
   ↓
Company Card API
   |
   +--> authenticate merchant
   |
   +--> validate request
   |
   +--> check idempotency
   |
   +--> lock card
   |
   +--> lock wallet
   |
   +--> check balance
   |
   +--> create transaction
   |
   +--> create ledger entry
   |
   +--> update wallet
   |
   +--> create audit record
   |
   +--> COMMIT
```

All financial changes occur inside a database transaction.

### Refunds

Merchants can refund completed purchases.

Refunds:

- Must belong to the requesting merchant
- Can be partial or complete
- Cannot exceed the remaining refundable amount
- Create a new `REFUND` transaction
- Create a `CREDIT` ledger entry
- Credit the employee wallet
- Are protected by idempotency
- Are recorded in the audit log

Example:

```text
Purchase
৳100.00
   |
   +---- Refund ৳30.00
   |
Remaining refundable:
৳70.00
```

### Idempotency

Payment, refund, and top-up requests use idempotency keys.

This prevents a retry from creating duplicate financial transactions.

For example:

```text
Request #1
idempotency_key = abc123
       ↓
Payment created

Request #2
idempotency_key = abc123
       ↓
Existing transaction returned
```

The wallet is only affected once.

### Concurrency Protection

Wallet operations use database row locking:

```sql
SELECT ...
FROM wallets
WHERE id = ?
FOR UPDATE
```

This prevents concurrent requests from both reading the same wallet balance and spending it.

The project includes a concurrency test that sends multiple simultaneous payments against a limited balance.

Example:

```text
Wallet balance:       ৳100
Payment amount:        ৳10
Concurrent requests:     20

Expected successful payments: 10
Expected rejected payments:   10
Final balance:                 ৳0
```

The test passed without overselling the wallet.

### Rate Limiting

Sensitive API operations are rate limited.

Examples include:

```text
Login
Payment
Refund
```

This provides basic protection against repeated authentication attempts and excessive API requests.

### Audit Logging

Important financial and administrative actions are recorded in `audit_logs`.

Examples:

```text
WALLET_TOP_UP
MERCHANT_CARD_CHARGE
MERCHANT_PAYMENT_REFUND
```

Audit records include information such as:

- User
- Action
- Entity
- Entity ID
- IP address
- Metadata
- Timestamp

## Database

The main tables are:

```text
users
employees
cards
wallets
transactions
ledger_entries
audit_logs
merchants
merchant_api_keys
```

### Financial relationship

```text
Employee
   |
   +---- Wallet
           |
           +---- Transactions
           |
           +---- Ledger Entries
```

### Merchant relationship

```text
Merchant
   |
   +---- Merchant API Keys
   |
   +---- Transactions
```

## API Concepts

The API is organized around resources such as:

```text
/auth
/employees
/cards
/wallets
/payments
/refunds
```

Merchant payment requests use API-key authentication rather than employee sessions.

Administrative operations use authenticated application sessions with role checks.

## Merchant Simulator

The repository includes a small PHP merchant simulator demonstrating how an external merchant could integrate with the Company Card API.

Example payment:

```bash
php merchant-simulator/index.php charge \
  "MERCHANT_API_KEY" \
  "CARD_CREDENTIAL" \
  "25.00"
```

Example refund:

```bash
php merchant-simulator/index.php refund \
  "MERCHANT_API_KEY" \
  123 \
  "25.00"
```

The simulator intentionally does not contain merchant products, inventory, carts, or a POS database.

It represents an external system communicating with Company Card through the API.

## Project Structure

```text
company-card/
│
├── frontend/
│   ├── src/
│   ├── public/
│   └── ...
│
├── backend/
│   ├── config/
│   ├── routes/
│   ├── services/
│   ├── utils/
│   └── tests/
│
├── merchant-simulator/
│   └── index.php
│
└── README.md
```

## Running Locally

### Backend

From the backend directory:

```bash
cd backend
php -S localhost:8000
```

The API will be available at:

```text
http://localhost:8000
```

### Frontend

Install dependencies:

```bash
cd frontend
npm install
```

Start the development server:

```bash
npm run dev
```

### Database

Create a MySQL database:

```text
company_card
```

Import the project's SQL schema/migrations into MySQL.

Update the database credentials in:

```text
backend/config/database.php
```

## Testing

### PHP syntax checks

```bash
php -l routes/wallets.php
php -l routes/payments.php
php -l routes/refunds.php
php -l tests/concurrency.php
php -l ../merchant-simulator/index.php
```

### Concurrency test

The concurrency test sends multiple simultaneous payment requests against the same wallet.

Example:

```bash
php tests/concurrency.php \
  "MERCHANT_API_KEY" \
  "CARD_CREDENTIAL" \
  "10.00" \
  20 \
  "100.00"
```

Expected result:

```text
Expected successes:    10
Actual successes:      10
Insufficient balance:  10
Other failures:        0

RESULT: PASS
No overselling detected.
```

## Security Considerations

This project is designed as an educational/internal system and is not presented as a production banking or payment processor.

Important security measures implemented include:

- Password hashing with PHP password APIs
- Prepared statements through PDO
- Session-based authentication
- Role-based authorization
- CSRF protection
- Hashed card credentials
- Hashed merchant API keys
- Rate limiting
- Idempotency protection
- Database transactions
- Row-level locking
- Immutable ledger records
- Audit logging
- Input validation
- Secure credential handling

A production deployment would additionally require infrastructure-level controls such as HTTPS, secret management, monitoring, centralized logging, stronger API-key lifecycle management, database backups, key rotation, and appropriate operational controls.

## What This Project Demonstrates

The main goal of this project was not building a complex UI.

It was to practice backend engineering around state, money, authentication, concurrency, and external integrations.

The most important engineering problems addressed were:

```text
How do we prevent duplicate payments?
        ↓
Idempotency

How do we prevent two requests spending the same balance?
        ↓
Database row locking

How do we avoid floating-point money errors?
        ↓
Integer cents

How do we track every balance movement?
        ↓
Immutable ledger

How does an external shop authenticate?
        ↓
Merchant API keys

How do we know who performed important actions?
        ↓
Audit logs

How do we safely reverse a payment?
        ↓
Refund transactions + ledger credits

How do we prevent repeated requests from overwhelming sensitive endpoints?
        ↓
Rate limiting
```

## Status

**Completed.**

The project has been tested across:

- Authentication
- Employee creation
- Card issuance
- Card lifecycle
- Wallet top-ups
- Payments
- Refunds
- Idempotency
- Rate limiting
- Money precision
- Concurrency
- Merchant API integration