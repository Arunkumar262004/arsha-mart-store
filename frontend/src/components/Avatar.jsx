const initials = (name = '') =>
  name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('')

const SIZES = {
  sm: 'h-8 w-8 text-xs',
  lg: 'h-24 w-24 text-2xl',
}

/** A user's profile photo, or their initials when they haven't uploaded one. */
export default function Avatar({ user, size = 'sm', className = '' }) {
  const box = `${SIZES[size]} shrink-0 rounded-full ${className}`

  if (user?.avatar) {
    return <img src={user.avatar} alt="" className={`${box} object-cover`} />
  }

  return <span className={`${box} grid place-items-center bg-brand-100 font-semibold text-brand-700`}>{initials(user?.name)}</span>
}
