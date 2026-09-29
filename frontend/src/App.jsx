import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { ShieldAlert } from 'lucide-react'
import { useAuth } from './auth/AuthContext'
import { EmptyState, Spinner } from './components/ui'
import AppLayout from './layout/AppLayout'
import { homePath } from './layout/navigation'
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
import PasswordReset from './pages/settings/PasswordReset'
import Roles from './pages/settings/Roles'
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
        <Route path="reports" element={<Navigate to="/reports/orders" replace />} />
        <Route path="reports/orders" element={guard('reports.view', <OrderReport />)} />
        <Route path="reports/customers" element={guard('reports.view', <CustomerReport />)} />
        <Route path="reports/stock" element={guard('reports.view', <StockReport />)} />
        <Route path="reports/employees" element={guard('reports.view', <EmployeeReport />)} />
        <Route path="employees" element={guard('settings.manage', <Users />)} />
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
