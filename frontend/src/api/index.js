import client from './client'

const data = (r) => r.data.data

// Auth & profile
// login and /me resolve with { user, permissions, stores, all_stores } (+ token for login).
export const login = (email, password) =>
  client.post('/login', { email, password, device_name: 'web' }, { skipStore: true }).then((r) => r.data)
export const logout = () => client.post('/logout', null, { skipStore: true })
export const getMe = () => client.get('/me', { skipStore: true }).then((r) => r.data)
export const changePassword = (payload) => client.put('/me/password', payload).then((r) => r.data)
/** Update your own name; resolves with the updated user. */
export const updateProfile = (payload) => client.put('/me', payload).then(data)
/** Upload a profile photo; resolves with the updated user. */
export const uploadAvatar = (file) => {
  const form = new FormData()
  form.append('avatar', file)
  // The client defaults to JSON; multipart lets the browser add the boundary.
  return client.post('/me/avatar', form, { headers: { 'Content-Type': 'multipart/form-data' } }).then(data)
}
export const removeAvatar = () => client.delete('/me/avatar').then(data)

// Company branding (name, logo, favicon). Reading it needs no login.
export const getBranding = () => client.get('/branding', { skipStore: true }).then(data)
/** Admin: { company_name, tagline, logo?: File, favicon?: File, remove_logo?, remove_favicon? } */
export const saveCompany = (fields) => {
  const form = new FormData()
  Object.entries(fields).forEach(([key, value]) => {
    if (value === undefined || value === null) return
    form.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value)
  })
  return client.post('/settings/company', form, { headers: { 'Content-Type': 'multipart/form-data' } }).then(data)
}

// Dashboard & notifications
/** year: which year the monthly sales chart shows (default: this year). */
export const getDashboard = (year) => client.get('/dashboard', { params: year ? { year } : {} }).then((r) => r.data)
export const getNotifications = () => client.get('/notifications').then((r) => r.data)

// Billing & orders
export const getProducts = () => client.get('/products').then(data)
/** Look up a customer by { email } or { phone }. Rejects with 404 when unknown. */
export const findCustomer = (query) => client.get('/customers/lookup', { params: query }).then(data)
export const createOrder = (payload) => client.post('/orders', payload).then(data)
export const getOrderHistory = (email, page = 1) =>
  client.get(`/customers/${encodeURIComponent(email)}/orders`, { params: { page } }).then((r) => r.data)

// Inventory
export const getLowStock = (threshold) =>
  client
    .get('/products/low-stock', { params: threshold === '' || threshold == null ? {} : { threshold } })
    .then((r) => r.data)
export const createProduct = (payload) => client.post('/products', payload).then(data)
export const updateProduct = (id, payload) => client.put(`/products/${id}`, payload).then(data)
export const adjustStock = (id, payload) => client.post(`/products/${id}/stock`, payload).then((r) => r.data)
export const getStockMovements = (id, page = 1) =>
  client.get(`/products/${id}/movements`, { params: { page } }).then((r) => r.data)

// Reports: `name` is orders | customers | stock | employees
export const getReport = (name, params) => client.get(`/reports/${name}`, { params }).then((r) => r.data)
export const getEmployeeOptions = () => client.get('/reports/employee-options').then(data)
/** A signed, expiring link to the same download, for the phone QR code: { url, expires_at }. */
export const getReportShareLink = (name, params, format) =>
  client.get(`/reports/${name}/share-link`, { params: { ...params, format } }).then((r) => r.data)
/** Resolves with { blob, filename } for a whole report as `pdf` or `xlsx`. */
export const downloadReport = (name, params, format) =>
  client
    .get(`/reports/${name}/export`, { params: { ...params, format }, responseType: 'blob', timeout: 120000 })
    .then((r) => ({
      blob: r.data,
      filename: /filename="?([^";]+)"?/.exec(r.headers['content-disposition'] ?? '')?.[1] ?? `${name}-report.${format}`,
    }))

// Settings (admin)
export const getUsers = () => client.get('/users').then(data)
export const createUser = (payload) => client.post('/users', payload).then(data)
export const updateUser = (id, payload) => client.put(`/users/${id}`, payload).then(data)
export const deleteUser = (id) => client.delete(`/users/${id}`)
export const resetUserPassword = (id, payload) => client.put(`/users/${id}/password`, payload).then((r) => r.data)
export const getRoles = () => client.get('/roles').then(data)
export const getPermissionCatalog = () => client.get('/permissions').then(data)
export const createRole = (payload) => client.post('/roles', payload).then(data)
export const updateRole = (id, payload) => client.put(`/roles/${id}`, payload).then(data)
export const deleteRole = (id) => client.delete(`/roles/${id}`)

// Stores
/** Active stores the signed-in user may switch to. */
export const getStores = () => client.get('/stores').then(data)
/** Every store incl. inactive ones, with users_count (admin). */
export const getAllStores = () => client.get('/stores/all').then(data)
export const createStore = (payload) => client.post('/stores', payload).then(data)
export const updateStore = (id, payload) => client.put(`/stores/${id}`, payload).then(data)
export const deleteStore = (id) => client.delete(`/stores/${id}`)
/** Stock of one product in every store: { data: [{ store_id, store_name, store_code, stock }], meta: { total } }. */
export const getProductStores = (id) => client.get(`/products/${id}/stores`).then((r) => r.data)
