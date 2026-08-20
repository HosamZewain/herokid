import { Image, ImageProps } from 'expo-image';
import { useEffect, useState } from 'react';
import { getToken } from '@/src/api/client';

export function AuthenticatedImage({ uri, ...props }: Omit<ImageProps, 'source'> & { uri: string }) {
  const [token, setToken] = useState<string | null>(null);
  useEffect(() => { getToken().then(setToken); }, []);
  return <Image {...props} source={{ uri, headers: token ? { Authorization: `Bearer ${token}` } : undefined }} />;
}
