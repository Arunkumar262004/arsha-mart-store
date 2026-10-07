import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { ShieldAlert } from 'lucide-react'
import { useAuth } from './auth/AuthContext'
import { EmptyState, Spinner } from './components/ui'
import AppLayout from './layout/AppLayout'
import { homePath } from './layout/navigation'
import ChangePassword from './pages/ChangePassword'
import Dashboard from './pages/Dashboard'
import Inventory from './pages/Inventory'
import Login from './pages/Login'
import NewOrder from './pages/NewOrder'
import OrderHistory from './pages/OrderHistory'
import Profile from './pages/Profile'
import CustomerReport from './pages/reports/CustomerReport'
import EmployeeReport from './pages/reports/EmployeeReport'
import OrderReport from './pages/reports/OrderReport'
import StockReport from './pages/reports/StockReport'
import SalesAnalysis from './pages/reports/SalesAnalysis'
import DeliveryChallans from './pages/sales/DeliveryChallans'
import Quotations from './pages/sales/Quotations'
import SalesReturns from './pages/sales/SalesReturns'
import TaxInvoices from './pages/sales/TaxInvoices'
import StockTransfers from './pages/inventory/StockTransfers'
import PurchaseReturns from './pages/purchases/PurchaseReturns'
import Purchases from './pages/purchases/Purchases'
import Suppliers from './pages/purchases/Suppliers'
import ChartOfAccounts from './pages/accounts/ChartOfAccounts'
import DayClosing from './pages/accounts/DayClosing'
import Expenses from './pages/accounts/Expenses'
import GstReports from './pages/accounts/GstReports'
import Ledger from './pages/accounts/Ledger'
import Outstanding from './pages/accounts/Outstanding'
import Payments from './pages/accounts/Payments'
import Receipts from './pages/accounts/Receipts'
import Statements from './pages/accounts/Statements'
import Vouchers from './pages/accounts/Vouchers'
import Company from './pages/settings/Company'
import PasswordReset from './pages/settings/PasswordReset'
import Roles from './pages/settings/Roles'
import Stores from './pages/settings/Stores'
import Users from './pages/settings/Users'

export default function App() {
  const { status, can } = useAuth()
  const location = useLocation()

  if (status === 'loading') {
    return (
      <div className="grid min-h-screen place-items-center text-slate-500">
        <Spinner size={28} />
      </div>
    )
  }

  if (status === 'guest') {
    return (
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="*" element={<Navigate to="/login" replace state={{ from: location.pathname }} />} />
      </Routes>
    )
  }

  const guard = (permission, element) => (can(permission) ? element : <Forbidden />)

  return (
    <Routes>
      <Route element={<AppLayout />}>
        <Route index element={<Navigate to={homePath(can)} replace />} />
        <Route path="login" element={<Navigate to={homePath(can)} replace />} />
        <Route path="dashboard" element={guard('dashboard.view', <Dashboard />)} />
        <Route path="billing" element={guard('billing.create', <NewOrder />)} />
        <Route path="orders" element={guard('orders.view', <OrderHistory />)} />
        <Route path="inventory" element={guard('products.view', <Inventory />)} />
        <Route path="profile" element={<Profile />} />
        <Route path="change-password" element={<ChangePassword />} />
        <Route path="reports" element={<Navigate to="/reports/orders" replace />} />
        <Route path="reports/orders" element={guard('reports.view', <OrderReport />)} />
        <Route path="reports/customers" element={guard('reports.view', <CustomerReport />)} />
        <Route path="reports/stock" element={guard('reports.view', <StockReport />)} />
        <Route path="reports/employees" element={guard('reports.view', <EmployeeReport />)} />
        <Route path="reports/sales-analysis" element={guard('reports.view', <SalesAnalysis />)} />
        {/* Sales documents */}
        <Route path="invoices" element={guard('orders.view', <TaxInvoices />)} />
        <Route path="quotations" element={guard('quotations.manage', <Quotations />)} />
        <Route path="challans" element={guard('challans.manage', <DeliveryChallans />)} />
        <Route path="sales-returns" element={guard('returns.manage', <SalesReturns />)} />
        <Route path="transfers" element={guard('transfers.manage', <StockTransfers />)} />
        {/* Purchases */}
        <Route path="suppliers" element={guard('suppliers.manage', <Suppliers />)} />
        <Route path="purchases" element={guard('purchases.manage', <Purchases />)} />
        <Route path="purchase-returns" element={guard('returns.manage', <PurchaseReturns />)} />
        {/* Accounts */}
        <Route path="accounts" element={<Navigate to="/accounts/vouchers" replace />} />
        <Route path="accounts/receipts" element={guard('payments.manage', <Receipts />)} />
        <Route path="accounts/payments" element={guard('payments.manage', <Payments />)} />
        <Route path="accounts/expenses" element={guard('expenses.manage', <Expenses />)} />
        <Route path="accounts/vouchers" element={guard('accounts.view', <Vouchers />)} />
        <Route path="accounts/ledger" element={guard('accounts.view', <Ledger />)} />
        <Route path="accounts/chart" element={guard('accounts.view', <ChartOfAccounts />)} />
        <Route path="accounts/outstanding" element={guard('accounts.view', <Outstanding />)} />
        <Route path="accounts/day-closing" element={guard('accounts.view', <DayClosing />)} />
        <Route path="accounts/statements" element={guard('accounts.view', <Statements />)} />
        <Route path="accounts/gst" element={guard('accounts.view', <GstReports />)} />
        <Route path="employees" element={guard('settings.manage', <Users />)} />
        <Route path="settings/company" element={guard('settings.manage', <Company />)} />
        <Route path="settings/stores" element={guard('settings.manage', <Stores />)} />
        <Route path="settings/roles" element={guard('settings.manage', <Roles />)} />
        <Route path="settings/password-reset" element={guard('settings.manage', <PasswordReset />)} />
        {/* old paths */}
        <Route path="settings/users" element={<Navigate to="/employees" replace />} />
        <Route path="settings/employees" element={<Navigate to="/employees" replace />} />
        <Route path="history" element={<Navigate to="/orders" replace />} />
        <Route path="low-stock" element={<Navigate to="/inventory?filter=low" replace />} />
        <Route path="*" element={<Navigate to={homePath(can)} replace />} />
      </Route>
    </Routes>
  )
}

function Forbidden() {
  return (
    <EmptyState icon={ShieldAlert} title="You don't have access to this page">
      Ask an admin to add the permission to your role.
    </EmptyState>
  )
}
