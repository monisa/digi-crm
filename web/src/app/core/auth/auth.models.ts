export type Role = 'admin' | 'employee' | 'client';
export type Status = 'pending_onboarding' | 'pending_approval' | 'active' | 'inactive';

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  company_name: string | null;
  role: Role;
  status: Status;
  needs_onboarding: boolean;
  last_login_at: string | null;
}

export interface Profile {
  user: AuthUser;
  permissions: string[];
  modules: string[];
}

export interface TokenResponse extends Profile {
  token: string;
  expires_at: string;
}
