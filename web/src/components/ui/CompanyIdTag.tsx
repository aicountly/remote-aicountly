/**
 * Small "ID: 123" tag shown beside an organisation's name so companies with
 * similar names can be told apart. Renders nothing when the id is unknown.
 */
export default function CompanyIdTag({
  id,
  className = '',
}: {
  id: number | string | null | undefined
  className?: string
}) {
  if (id === null || id === undefined || String(id).trim() === '') return null

  return (
    <span className={className ? `company-id-tag ${className}` : 'company-id-tag'} title={`Company ID ${id}`}>
      ID: {String(id)}
    </span>
  )
}
