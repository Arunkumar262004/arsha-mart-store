import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import './index.css'
import App from './App.jsx'
import { AuthProvider } from './auth/AuthContext'
import { BrandingProvider } from './branding/BrandingContext'
import { ConfirmProvider } from './components/ConfirmDialog'
import { ToastProvider } from './components/Toast'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <BrandingProvider>
        <AuthProvider>
          <ToastProvider>
            <ConfirmProvider>
              <App />
            </ConfirmProvider>
          </ToastProvider>
        </AuthProvider>
      </BrandingProvider>
    </BrowserRouter>
  </StrictMode>,
)
