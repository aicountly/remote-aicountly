import { lazy, Suspense } from 'react'
import { createBrowserRouter, Navigate } from 'react-router-dom'

import AppShell from './AppShell'
import RouteFallback from '../components/ui/RouteFallback'

/**
 * Routes.
 *
 * Every screen is lazy (§65): the dashboard is what most visits need, and the
 * session room in particular pulls in the WebRTC layer that a person reading
 * their history never touches.
 *
 * `/room/:uuid` and `/join/:token` sit **outside** the shell. The room is a
 * full-screen workspace with its own chrome, and the guest join page must not
 * show company navigation to somebody who has no AICOUNTLY account (§23).
 */

const Dashboard = lazy(() => import('../features/dashboard/DashboardPage'))
const StartSession = lazy(() => import('../features/sessions/StartSessionPage'))
const JoinSession = lazy(() => import('../features/sessions/JoinSessionPage'))
const SessionsList = lazy(() => import('../features/sessions/SessionsPage'))
const SessionDetail = lazy(() => import('../features/sessions/SessionDetailPage'))
const SupportRequests = lazy(() => import('../features/support/SupportPage'))
const SessionRoom = lazy(() => import('../features/room/SessionRoomPage'))
const GuestJoin = lazy(() => import('../features/sessions/GuestJoinPage'))
const AdminPolicy = lazy(() => import('../features/admin/PolicyPage'))
const AdminPermissions = lazy(() => import('../features/admin/PermissionsPage'))
const AdminRolePermissions = lazy(() => import('../features/admin/RolePermissionsPage'))
const AdminAudit = lazy(() => import('../features/admin/AuditPage'))
const SettingsPage = lazy(() => import('../features/settings/SettingsPage'))
const NotFound = lazy(() => import('../features/misc/NotFoundPage'))

function page(element: React.ReactNode) {
  return <Suspense fallback={<RouteFallback />}>{element}</Suspense>
}

export const router = createBrowserRouter([
  {
    path: '/',
    element: <AppShell />,
    children: [
      { index: true, element: page(<Dashboard />) },
      { path: 'start', element: page(<StartSession />) },
      { path: 'join', element: page(<JoinSession />) },
      { path: 'sessions', element: page(<SessionsList />) },
      { path: 'sessions/:uuid', element: page(<SessionDetail />) },
      { path: 'support', element: page(<SupportRequests />) },
      { path: 'admin/policy', element: page(<AdminPolicy />) },
      { path: 'admin/permissions', element: page(<AdminPermissions />) },
      { path: 'admin/roles', element: page(<AdminRolePermissions />) },
      { path: 'admin/audit', element: page(<AdminAudit />) },
      { path: 'settings', element: page(<SettingsPage />) },
      { path: '*', element: page(<NotFound />) },
    ],
  },
  // The live session workspace: no shell, no navigation, nothing competing
  // with the shared screen (§33).
  { path: '/room/:uuid', element: page(<SessionRoom />) },
  // A one-time invitation link. Deliberately outside the shell — a guest gets
  // the session and nothing else (§23).
  { path: '/join/:token', element: page(<GuestJoin />) },
  // The portal returns here after sign-in; AuthProvider has already consumed
  // the token by the time this renders.
  { path: '/auth/callback', element: <Navigate to="/" replace /> },
])
