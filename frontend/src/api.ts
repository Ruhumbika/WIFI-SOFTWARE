import axios from 'axios'
export const api = axios.create({ baseURL: import.meta.env.VITE_API_BASE_URL || '/api', timeout: 20000 })
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('rjay_admin_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  if (config.url?.startsWith('/public/orders/')) {
    const orderToken = sessionStorage.getItem('rjay_order_token')
    if (orderToken) config.headers['X-Order-Token'] = orderToken
  }
  return config
})
api.interceptors.response.use(undefined, (error) => {
  if (error.response?.status === 401 && error.config?.url?.startsWith('/admin/')) {
    localStorage.removeItem('rjay_admin_token')
    window.location.assign('/admin/login')
  }
  return Promise.reject(error)
})
