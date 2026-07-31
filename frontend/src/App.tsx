import './App.css'
import { useAuth } from './auth/AuthContext'
import { Header } from './components/Header'
import { AuthPage } from './pages/AuthPage'
import { HomePage } from './pages/HomePage'
import { ProfilePage } from './pages/ProfilePage'
import { TreesPage } from './pages/TreesPage'
import { usePath } from './routing'

const protectedPaths = new Set(['/profile', '/trees'])

export default function App() {
  const path = usePath()
  const { isBooting, user } = useAuth()

  let page = <HomePage />
  if (!isBooting && protectedPaths.has(path) && !user) {
    page = <AuthPage />
  } else if (path === '/profile' && user) {
    page = <ProfilePage />
  } else if (path === '/trees' && user) {
    page = <TreesPage />
  }

  return (
    <div className="app-shell">
      <Header />
      {page}
    </div>
  )
}
