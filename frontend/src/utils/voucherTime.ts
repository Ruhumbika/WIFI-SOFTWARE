export function voucherTimeLeft(expiresAt: string | null | undefined, nowMs: number): string | null {
  if (!expiresAt) return null;
  const expiryMs = new Date(expiresAt).getTime();
  if (!Number.isFinite(expiryMs)) return null;

  const seconds = Math.max(0, Math.ceil((expiryMs - nowMs) / 1000));
  if (seconds === 0) return 'Time ended';

  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const remainingSeconds = seconds % 60;
  const clock = [hours, minutes, remainingSeconds].map(value => String(value).padStart(2, '0')).join(':');
  return days ? `${days}d ${clock}` : clock;
}
