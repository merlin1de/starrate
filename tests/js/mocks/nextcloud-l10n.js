// Mock für @nextcloud/l10n
export const t = (app, str, vars = {}) => {
  let result = str
  Object.entries(vars).forEach(([k, v]) => {
    result = result.replace(new RegExp(`\\{${k}\\}`, 'g'), v)
  })
  return result
}

export const n = (app, singular, plural, count, ...args) => {
  const str = count === 1 ? singular : plural
  return str.replace('%n', count)
}

export default { t, n }
