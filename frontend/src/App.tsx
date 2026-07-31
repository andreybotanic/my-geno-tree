import './App.css'
import { useAuth } from './auth/AuthContext'
import { Header } from './components/Header'
import { AuthPage } from './pages/AuthPage'
import { HomePage } from './pages/HomePage'
import { ProfilePage } from './pages/ProfilePage'
import { TreesPage } from './pages/TreesPage'
import { TreeEditorPage } from './pages/TreeEditorPage'
import { usePath } from './routing'

function isProtectedPath(path: string): boolean {
  return path === '/profile'
    || path === '/trees'
    || path.startsWith('/trees/')
}

export default function App() {
  const path = usePath()
  const { isBooting, user } = useAuth()
  const treeId = path.match(/^\/trees\/([^/]+)$/)?.[1]

  let page = <HomePage />
  if (!isBooting && isProtectedPath(path) && !user) {
    page = <AuthPage />
  } else if (path === '/profile' && user) {
    page = <ProfilePage />
  } else if (path === '/trees' && user) {
    page = <TreesPage />
  } else if (treeId && user) {
    page = <TreeEditorPage treeId={treeId} />
  }

  return (
    <div className="app-shell">
      <Header />
      {page}
    </div>
  )
}
