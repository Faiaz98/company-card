import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { getCurrentUser, login, logout } from './api';
import type { User } from './api';

type Employee = {
  id: number;
  employee_code: string;
  full_name: string;
  email: string;
  is_active: boolean;
  balance: string;
  currency: string;
};

type Card = {
  id: number;
  employee_id: number;
  card_reference: string;
  credential?: string;
  status: string;
};

type Merchant = {
  id: number;
  name: string;
  merchant_code: string;
  is_active: boolean;
  created_at: string;
  api_key_count: number;
};

type CreatedMerchant = Merchant & {
  api_key: {
    reference: string;
    secret: string;
  };
};

type TopUpModalProps = {
  employee: Employee;
  onClose: () => void;
  onSuccess: () => void;
};

async function getEmployees(): Promise<Employee[]> {
  const response = await fetch('http://localhost:8000/api/employees', {
    credentials: 'include',
  });

  const data = await response.json();

  if (!response.ok) {
    throw new Error(data.message || 'Unable to load employees.');
  }

  return data.data.employees;
}

async function createEmployee(payload: {
  email: string;
  employee_code: string;
  full_name: string;
  password: string;
}): Promise<Employee> {
  const response = await fetch('http://localhost:8000/api/employees', {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  });

  const data = await response.json();

  if (!response.ok) {
    throw new Error(data.message || 'Unable to create employee.');
  }

  return data.data.employee;
}

async function createCard(employeeId: number): Promise<Card> {
  const response = await fetch('http://localhost:8000/api/cards', {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      employee_id: employeeId,
    }),
  });

  const data = await response.json();

  if (!response.ok) {
    throw new Error(data.message || 'Unable to issue card.');
  }

  return data.data.card;
}

async function topUpWallet(
  employeeId: number,
  amount: string
): Promise<void> {
  const response = await fetch(
    'http://localhost:8000/api/wallets/top-up',
    {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        employee_id: employeeId,
        amount,
        idempotency_key: crypto.randomUUID(),
      }),
    }
  );

  const data = await response.json();

  if (!response.ok) {
    throw new Error(data.message || 'Unable to top up wallet.');
  }
}

async function getMerchants(): Promise<Merchant[]> {
  const response = await fetch(
    'http://localhost:8000/api/merchants',
    {
      credentials: 'include',
    }
  );

  const data = await response.json();

  if (!response.ok) {
    throw new Error(data.message || 'Unable to load merchants.');
  }

  return data.data.merchants;
}

async function createMerchant(payload: {
  name: string;
  merchant_code: string;
}): Promise<CreatedMerchant> {
  const response = await fetch(
    'http://localhost:8000/api/merchants',
    {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    }
  );

  const data = await response.json();

  if (!response.ok) {
    throw new Error(data.message || 'Unable to create merchant.');
  }

  return {
    ...data.data.merchant,
    api_key: data.data.api_key,
  };
}

function LoginPage({
  onLogin,
}: {
  onLogin: (user: User) => void;
}) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    setError('');
    setLoading(true);

    try {
      const user = await login(email, password);

      onLogin(user);
    } catch (error) {
      setError(
        error instanceof Error
          ? error.message
          : 'Unable to sign in.'
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="auth-page">
      <section className="auth-card">
        <div className="brand-mark">CC</div>

        <div className="auth-heading">
          <p className="eyebrow">Internal system</p>
          <h1>Company Card</h1>
          <p>
            Sign in to manage company wallets, cards, and
            transactions.
          </p>
        </div>

        <form onSubmit={handleSubmit} className="auth-form">
          <label>
            Email
            <input
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              placeholder="admin@company.com"
              autoComplete="email"
              required
            />
          </label>

          <label>
            Password
            <input
              type="password"
              value={password}
              onChange={(event) =>
                setPassword(event.target.value)
              }
              placeholder="••••••••"
              autoComplete="current-password"
              required
            />
          </label>

          {error && <div className="error-box">{error}</div>}

          <button
            type="submit"
            className="primary-button"
            disabled={loading}
          >
            {loading ? 'Signing in...' : 'Sign in'}
          </button>
        </form>
      </section>
    </main>
  );
}

function AddEmployeeModal({
  onClose,
  onCreated,
}: {
  onClose: () => void;
  onCreated: (employee: Employee) => void;
}) {
  const [fullName, setFullName] = useState('');
  const [employeeCode, setEmployeeCode] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    setError('');
    setLoading(true);

    try {
      const employee = await createEmployee({
        full_name: fullName,
        employee_code: employeeCode,
        email,
        password,
      });

      onCreated(employee);
    } catch (error) {
      setError(
        error instanceof Error
          ? error.message
          : 'Unable to create employee.'
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="modal-backdrop">
      <div className="modal">
        <div className="modal-header">
          <div>
            <p className="eyebrow">Directory</p>
            <h2>Add employee</h2>
          </div>

          <button
            type="button"
            className="icon-button"
            onClick={onClose}
          >
            ×
          </button>
        </div>

        <form onSubmit={handleSubmit} className="modal-form">
          <label>
            Full name
            <input
              value={fullName}
              onChange={(event) =>
                setFullName(event.target.value)
              }
              placeholder="John Doe"
              required
            />
          </label>

          <label>
            Employee code
            <input
              value={employeeCode}
              onChange={(event) =>
                setEmployeeCode(event.target.value)
              }
              placeholder="EMP-001"
              required
            />
          </label>

          <label>
            Email
            <input
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              placeholder="john@company.com"
              required
            />
          </label>

          <label>
            Temporary password
            <input
              type="password"
              value={password}
              onChange={(event) =>
                setPassword(event.target.value)
              }
              placeholder="At least 8 characters"
              required
            />
          </label>

          {error && <div className="error-box">{error}</div>}

          <div className="modal-actions">
            <button
              type="button"
              className="secondary-button"
              onClick={onClose}
            >
              Cancel
            </button>

            <button
              type="submit"
              className="primary-button"
              disabled={loading}
            >
              {loading ? 'Creating...' : 'Create employee'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function TopUpModal({
  employee,
  onClose,
  onSuccess,
}: TopUpModalProps) {
  const [amount, setAmount] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    setError('');

    if (!amount || Number(amount) <= 0) {
      setError('Enter a valid amount.');
      return;
    }

    setLoading(true);

    try {
      await topUpWallet(employee.id, amount);

      onSuccess();
    } catch (error) {
      setError(
        error instanceof Error
          ? error.message
          : 'Unable to top up wallet.'
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="modal-backdrop">
      <div className="modal">
        <div className="modal-header">
          <div>
            <p className="eyebrow">Wallet</p>
            <h2>Top up balance</h2>
          </div>

          <button
            type="button"
            className="icon-button"
            onClick={onClose}
          >
            ×
          </button>
        </div>

        <div className="wallet-preview">
          <div>
            <span>Employee</span>
            <strong>{employee.full_name}</strong>
          </div>

          <div>
            <span>Current balance</span>
            <strong>
              {Number(employee.balance).toLocaleString(
                'en-BD',
                {
                  minimumFractionDigits: 2,
                }
              )}{' '}
              {employee.currency}
            </strong>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="modal-form">
          <label>
            Amount
            <div className="amount-input">
              <span>৳</span>

              <input
                type="number"
                min="0.01"
                step="0.01"
                value={amount}
                onChange={(event) =>
                  setAmount(event.target.value)
                }
                placeholder="1000.00"
                autoFocus
                required
              />
            </div>
          </label>

          {error && <div className="error-box">{error}</div>}

          <div className="modal-actions">
            <button
              type="button"
              className="secondary-button"
              onClick={onClose}
            >
              Cancel
            </button>

            <button
              type="submit"
              className="primary-button"
              disabled={loading}
            >
              {loading ? 'Processing...' : 'Top up wallet'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function IssueCardModal({
  employee,
  onClose,
  onIssued,
}: {
  employee: Employee;
  onClose: () => void;
  onIssued: (card: Card) => void;
}) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  async function handleIssue() {
    setError('');
    setLoading(true);

    try {
      const card = await createCard(employee.id);

      onIssued(card);
    } catch (error) {
      setError(
        error instanceof Error
          ? error.message
          : 'Unable to issue card.'
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="modal-backdrop">
      <div className="modal">
        <div className="modal-header">
          <div>
            <p className="eyebrow">Card management</p>
            <h2>Issue card</h2>
          </div>

          <button
            type="button"
            className="icon-button"
            onClick={onClose}
          >
            ×
          </button>
        </div>

        <div className="card-preview">
          <span>Employee</span>
          <strong>{employee.full_name}</strong>

          <span>Employee code</span>
          <strong>{employee.employee_code}</strong>
        </div>

        <p className="modal-description">
          A unique card credential will be generated. The QR
          code will be shown once after issuance.
        </p>

        {error && <div className="error-box">{error}</div>}

        <div className="modal-actions">
          <button
            type="button"
            className="secondary-button"
            onClick={onClose}
          >
            Cancel
          </button>

          <button
            type="button"
            className="primary-button"
            onClick={handleIssue}
            disabled={loading}
          >
            {loading ? 'Issuing...' : 'Issue card'}
          </button>
        </div>
      </div>
    </div>
  );
}

function CardModal({
  card,
  onClose,
}: {
  card: Card;
  onClose: () => void;
}) {
  return (
    <div className="modal-backdrop">
      <div className="modal card-modal">
        <div className="modal-header">
          <div>
            <p className="eyebrow">Card issued</p>
            <h2>{card.card_reference}</h2>
          </div>

          <button
            type="button"
            className="icon-button"
            onClick={onClose}
          >
            ×
          </button>
        </div>

        <div className="qr-wrapper">
          {card.credential && (
            <QRCodeSVG
              value={card.credential}
              size={240}
              level="M"
            />
          )}
        </div>

        <div className="credential-warning">
          <strong>Important</strong>
          <p>
            This QR credential is only returned when the card
            is issued. Store or print it securely.
          </p>
        </div>

        <div className="modal-actions">
          <button
            type="button"
            className="primary-button"
            onClick={onClose}
          >
            Done
          </button>
        </div>
      </div>
    </div>
  );
}

function CreateMerchantModal({
  onClose,
  onCreated,
}: {
  onClose: () => void;
  onCreated: (merchant: CreatedMerchant) => void;
}) {
  const [name, setName] = useState('');
  const [merchantCode, setMerchantCode] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    setError('');
    setLoading(true);

    try {
      const merchant = await createMerchant({
        name,
        merchant_code: merchantCode,
      });

      onCreated(merchant);
    } catch (error) {
      setError(
        error instanceof Error
          ? error.message
          : 'Unable to create merchant.'
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="modal-backdrop">
      <div className="modal">
        <div className="modal-header">
          <div>
            <p className="eyebrow">Merchant integration</p>
            <h2>Add merchant</h2>
          </div>

          <button
            type="button"
            className="icon-button"
            onClick={onClose}
          >
            ×
          </button>
        </div>

        <form onSubmit={handleSubmit} className="modal-form">
          <label>
            Merchant name
            <input
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder="Company Cafeteria"
              required
            />
          </label>

          <label>
            Merchant code
            <input
              value={merchantCode}
              onChange={(event) =>
                setMerchantCode(
                  event.target.value.toUpperCase()
                )
              }
              placeholder="CAFETERIA01"
              maxLength={50}
              required
            />
          </label>

          <p className="modal-description">
            A new API credential will be generated for this
            merchant. The secret will only be displayed once.
          </p>

          {error && <div className="error-box">{error}</div>}

          <div className="modal-actions">
            <button
              type="button"
              className="secondary-button"
              onClick={onClose}
            >
              Cancel
            </button>

            <button
              type="submit"
              className="primary-button"
              disabled={loading}
            >
              {loading ? 'Creating...' : 'Create merchant'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function MerchantKeyModal({
  merchant,
  onClose,
}: {
  merchant: CreatedMerchant;
  onClose: () => void;
}) {
  const [copied, setCopied] = useState(false);

  async function copyKey() {
    await navigator.clipboard.writeText(
      merchant.api_key.secret
    );

    setCopied(true);

    setTimeout(() => {
      setCopied(false);
    }, 2000);
  }

  return (
    <div className="modal-backdrop">
      <div className="modal">
        <div className="modal-header">
          <div>
            <p className="eyebrow">Credential created</p>
            <h2>{merchant.name}</h2>
          </div>

          <button
            type="button"
            className="icon-button"
            onClick={onClose}
          >
            ×
          </button>
        </div>

        <div className="card-preview">
          <span>Merchant code</span>
          <strong>{merchant.merchant_code}</strong>

          <span>API key reference</span>
          <strong>{merchant.api_key.reference}</strong>
        </div>

        <div className="credential-warning">
          <strong>Save this secret now</strong>
          <p>
            This is the only time the full API secret will be
            displayed. The database stores only its hash.
          </p>
        </div>

        <div className="secret-box">
          <code>{merchant.api_key.secret}</code>
        </div>

        <div className="modal-actions">
          <button
            type="button"
            className="secondary-button"
            onClick={copyKey}
          >
            {copied ? 'Copied' : 'Copy API key'}
          </button>

          <button
            type="button"
            className="primary-button"
            onClick={onClose}
          >
            Done
          </button>
        </div>
      </div>
    </div>
  );
}

function AdminDashboard({
  user,
  onLogout,
}: {
  user: User;
  onLogout: () => void;
}) {
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [merchants, setMerchants] = useState<Merchant[]>([]);

  const [loading, setLoading] = useState(true);
  const [merchantsLoading, setMerchantsLoading] =
    useState(true);

  const [error, setError] = useState('');
  const [merchantError, setMerchantError] = useState('');

  const [showAddEmployee, setShowAddEmployee] =
    useState(false);

  const [showIssueCard, setShowIssueCard] =
    useState<Employee | null>(null);

  const [issuedCard, setIssuedCard] =
    useState<Card | null>(null);

  const [topUpEmployee, setTopUpEmployee] =
    useState<Employee | null>(null);

  const [showCreateMerchant, setShowCreateMerchant] =
    useState(false);

  const [createdMerchant, setCreatedMerchant] =
    useState<CreatedMerchant | null>(null);

  async function loadEmployees() {
    setError('');

    try {
      const data = await getEmployees();

      setEmployees(data);
    } catch (error) {
      setError(
        error instanceof Error
          ? error.message
          : 'Unable to load employees.'
      );
    } finally {
      setLoading(false);
    }
  }

  async function loadMerchants() {
    setMerchantError('');

    try {
      const data = await getMerchants();

      setMerchants(data);
    } catch (error) {
      setMerchantError(
        error instanceof Error
          ? error.message
          : 'Unable to load merchants.'
      );
    } finally {
      setMerchantsLoading(false);
    }
  }

  useEffect(() => {
    loadEmployees();
    loadMerchants();
  }, []);

  function handleEmployeeCreated(employee: Employee) {
    setEmployees((current) => [employee, ...current]);
    setShowAddEmployee(false);
  }

  function handleTopUpSuccess() {
    setTopUpEmployee(null);
    loadEmployees();
  }

  function handleMerchantCreated(
    merchant: CreatedMerchant
  ) {
    const { api_key, ...merchantRecord } = merchant;

    setMerchants((current) => [
      {
        ...merchantRecord,
        api_key_count: 1,
      },
      ...current,
    ]);

    setShowCreateMerchant(false);
    setCreatedMerchant(merchant);
  }

  return (
    <div className="app-shell">
      <header className="topbar">
        <div className="topbar-brand">
          <div className="brand-mark small">CC</div>

          <div>
            <strong>Company Card</strong>
            <span>Internal wallet system</span>
          </div>
        </div>

        <div className="topbar-user">
          <div>
            <strong>{user.email}</strong>
            <span>{user.role}</span>
          </div>

          <button
            type="button"
            className="secondary-button compact"
            onClick={onLogout}
          >
            Sign out
          </button>
        </div>
      </header>

      <main className="dashboard">
        <section className="dashboard-heading">
          <div>
            <p className="eyebrow">Administration</p>
            <h1>Company Card</h1>
            <p>
              Manage employees, wallets, cards, and merchant
              integrations.
            </p>
          </div>
        </section>

        {error && <div className="error-box">{error}</div>}

        <section className="stats-grid">
          <div className="stat-card">
            <span>Total employees</span>
            <strong>{employees.length}</strong>
          </div>

          <div className="stat-card">
            <span>Active employees</span>
            <strong>
              {employees.filter(
                (employee) => employee.is_active
              ).length}
            </strong>
          </div>

          <div className="stat-card">
            <span>Total wallet balance</span>
            <strong>
              ৳
              {employees
                .reduce(
                  (total, employee) =>
                    total + Number(employee.balance),
                  0
                )
                .toLocaleString('en-BD', {
                  minimumFractionDigits: 2,
                })}
            </strong>
          </div>

          <div className="stat-card">
            <span>Connected merchants</span>
            <strong>{merchants.length}</strong>
          </div>
        </section>

        <section className="table-card">
          <div className="table-heading">
            <div>
              <p className="eyebrow">Wallet system</p>
              <h2>Employee directory</h2>
              <span>
                {employees.length} employee
                {employees.length === 1 ? '' : 's'}
              </span>
            </div>

            <button
              type="button"
              className="primary-button"
              onClick={() => setShowAddEmployee(true)}
            >
              + Add employee
            </button>
          </div>

          {loading ? (
            <div className="empty-state">
              Loading employees...
            </div>
          ) : employees.length === 0 ? (
            <div className="empty-state">
              No employees have been created yet.
            </div>
          ) : (
            <div className="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>Employee</th>
                    <th>Code</th>
                    <th>Email</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>

                <tbody>
                  {employees.map((employee) => (
                    <tr key={employee.id}>
                      <td>
                        <strong>{employee.full_name}</strong>
                      </td>

                      <td>
                        <span className="code">
                          {employee.employee_code}
                        </span>
                      </td>

                      <td>{employee.email}</td>

                      <td>
                        <strong>
                          {Number(
                            employee.balance
                          ).toLocaleString('en-BD', {
                            minimumFractionDigits: 2,
                          })}{' '}
                          {employee.currency}
                        </strong>
                      </td>

                      <td>
                        <span
                          className={
                            employee.is_active
                              ? 'status active'
                              : 'status inactive'
                          }
                        >
                          {employee.is_active
                            ? 'Active'
                            : 'Inactive'}
                        </span>
                      </td>

                      <td>
                        <div className="table-actions">
                          <button
                            type="button"
                            className="secondary-button compact"
                            onClick={() =>
                              setTopUpEmployee(employee)
                            }
                          >
                            Top up
                          </button>

                          <button
                            type="button"
                            className="secondary-button compact"
                            onClick={() =>
                              setShowIssueCard(employee)
                            }
                          >
                            Issue card
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <section className="table-card">
          <div className="table-heading">
            <div>
              <p className="eyebrow">API integrations</p>
              <h2>Merchant directory</h2>
              <span>
                Merchants authorized to process Company Card
                payments.
              </span>
            </div>

            <button
              type="button"
              className="primary-button"
              onClick={() => setShowCreateMerchant(true)}
            >
              + Add merchant
            </button>
          </div>

          {merchantError && (
            <div className="error-box">{merchantError}</div>
          )}

          {merchantsLoading ? (
            <div className="empty-state">
              Loading merchants...
            </div>
          ) : merchants.length === 0 ? (
            <div className="empty-state">
              No merchants have been connected yet.
            </div>
          ) : (
            <div className="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>Merchant</th>
                    <th>Code</th>
                    <th>API keys</th>
                    <th>Status</th>
                    <th>Connected</th>
                  </tr>
                </thead>

                <tbody>
                  {merchants.map((merchant) => (
                    <tr key={merchant.id}>
                      <td>
                        <strong>{merchant.name}</strong>
                      </td>

                      <td>
                        <span className="code">
                          {merchant.merchant_code}
                        </span>
                      </td>

                      <td>{merchant.api_key_count}</td>

                      <td>
                        <span
                          className={
                            merchant.is_active
                              ? 'status active'
                              : 'status inactive'
                          }
                        >
                          {merchant.is_active
                            ? 'Active'
                            : 'Inactive'}
                        </span>
                      </td>

                      <td>
                        {new Date(
                          merchant.created_at
                        ).toLocaleDateString('en-BD')}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </main>

      {showAddEmployee && (
        <AddEmployeeModal
          onClose={() => setShowAddEmployee(false)}
          onCreated={handleEmployeeCreated}
        />
      )}

      {showIssueCard && (
        <IssueCardModal
          employee={showIssueCard}
          onClose={() => setShowIssueCard(null)}
          onIssued={(card) => {
            setShowIssueCard(null);
            setIssuedCard(card);
          }}
        />
      )}

      {issuedCard && (
        <CardModal
          card={issuedCard}
          onClose={() => setIssuedCard(null)}
        />
      )}

      {topUpEmployee && (
        <TopUpModal
          employee={topUpEmployee}
          onClose={() => setTopUpEmployee(null)}
          onSuccess={handleTopUpSuccess}
        />
      )}

      {showCreateMerchant && (
        <CreateMerchantModal
          onClose={() => setShowCreateMerchant(false)}
          onCreated={handleMerchantCreated}
        />
      )}

      {createdMerchant && (
        <MerchantKeyModal
          merchant={createdMerchant}
          onClose={() => setCreatedMerchant(null)}
        />
      )}
    </div>
  );
}

function App() {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    getCurrentUser()
      .then((currentUser) => {
        setUser(currentUser);
      })
      .finally(() => {
        setLoading(false);
      });
  }, []);

  async function handleLogout() {
    await logout();
    setUser(null);
  }

  if (loading) {
    return (
      <main className="loading-page">
        <span>Loading...</span>
      </main>
    );
  }

  if (!user) {
    return <LoginPage onLogin={setUser} />;
  }

  if (user.role !== 'ADMIN') {
    return (
      <main className="loading-page">
        <div>
          <h1>Access restricted</h1>
          <p>
            This dashboard is currently available to
            administrators only.
          </p>

          <button
            type="button"
            className="primary-button"
            onClick={handleLogout}
          >
            Sign out
          </button>
        </div>
      </main>
    );
  }

  return (
    <AdminDashboard
      user={user}
      onLogout={handleLogout}
    />
  );
}

export default App;