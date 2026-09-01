import { Link } from 'react-router-dom'

import EmptyState from '../../components/ui/EmptyState'

export default function NotFoundPage() {
  return (
    <div className="page">
      <EmptyState
        title="That page isn’t part of AICOUNTLY Remote"
        description="The link may be out of date, or the session it pointed to may have ended."
        action={
          <Link to="/" className="btn btn--primary">
            Back to Remote
          </Link>
        }
      />
    </div>
  )
}
