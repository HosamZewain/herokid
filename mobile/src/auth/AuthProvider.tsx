import { createContext, PropsWithChildren, useContext, useEffect, useMemo, useState } from 'react';
import { Platform } from 'react-native';
import { api, getToken, setToken } from '@/src/api/client';
import type { ApiUser, AuthResponse } from '@/src/api/types';

type Credentials = { login: string; password: string };
type Registration = { name: string; email: string; phone?: string; password: string; password_confirmation: string };
type SocialCredentials = { provider: 'google' | 'apple'; id_token: string; name?: string };
type OtpCredentials = { challenge_id: string; code: string; name?: string };
type Value = {
  user: ApiUser | null;
  ready: boolean;
  login: (values: Credentials) => Promise<void>;
  register: (values: Registration) => Promise<void>;
  loginWithSocial: (values: SocialCredentials) => Promise<void>;
  verifyOtp: (values: OtpCredentials) => Promise<void>;
  refreshUser: () => Promise<void>;
  logout: () => Promise<void>;
};

const Context = createContext<Value | null>(null);

export function AuthProvider({ children }: PropsWithChildren) {
  const [user, setUser] = useState<ApiUser | null>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    getToken()
      .then(async (token) => {
        if (!token) return;
        try {
          const response = await api<{ data: { user: ApiUser } }>('/me');
          setUser(response.data.user);
        } catch {
          await setToken(null);
        }
      })
      .finally(() => setReady(true));
  }, []);

  const authenticate = async (path: string, values: object) => {
    const response = await api<AuthResponse>(path, {
      method: 'POST',
      body: JSON.stringify({ ...values, device_name: `${Platform.OS} HeroKid` }),
    });
    await setToken(response.data.token);
    setUser(response.data.user);
  };

  const value = useMemo<Value>(() => ({
    user,
    ready,
    login: (values) => authenticate('/auth/login', values),
    register: (values) => authenticate('/auth/register', values),
    loginWithSocial: (values) => authenticate('/auth/social', values),
    verifyOtp: (values) => authenticate('/auth/otp/verify', values),
    refreshUser: async () => {
      const response = await api<{ data: { user: ApiUser } }>('/me');
      setUser(response.data.user);
    },
    logout: async () => {
      try {
        await api('/auth/logout', { method: 'POST' });
      } finally {
        await setToken(null);
        setUser(null);
      }
    },
  }), [user, ready]);

  return <Context.Provider value={value}>{children}</Context.Provider>;
}

export function useAuth() {
  const value = useContext(Context);
  if (!value) throw new Error('useAuth must be used inside AuthProvider');
  return value;
}
