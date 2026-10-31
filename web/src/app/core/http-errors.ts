import { HttpErrorResponse } from '@angular/common/http';

/** First validation message per field, plus a general message, from a Laravel error response. */
export function readErrors(err: unknown): { message: string; fields: Record<string, string> } {
  if (!(err instanceof HttpErrorResponse)) return { message: 'Something went wrong. Please try again.', fields: {} };
  if (err.status === 0) return { message: 'Cannot reach the server. Check your internet connection.', fields: {} };

  const fields: Record<string, string> = {};
  for (const [key, msgs] of Object.entries((err.error?.errors ?? {}) as Record<string, string[]>)) {
    fields[key] = msgs[0] ?? '';
  }
  const message =
    Object.keys(fields).length > 0 ? '' : (err.error?.message as string) || 'Something went wrong. Please try again.';
  return { message, fields };
}

/** Form fields that can carry an error message (typed so templates can use errors().email). */
export type FieldErrors = Partial<
  Record<
    | 'name' | 'company_name' | 'email' | 'phone' | 'password' | 'password_confirmation'
    | 'business_name' | 'industry' | 'city' | 'state' | 'website' | 'gstin' | 'services' | 'notes',
    string
  >
>;
