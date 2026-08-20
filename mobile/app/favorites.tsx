import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { StyleSheet, Text, View } from 'react-native';
import { api } from '@/src/api/client';
import type { FavoriteItem } from '@/src/api/types';
import { Button } from '@/src/components/Button';
import { ProductCard } from '@/src/components/ProductCard';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors } from '@/src/theme';

export default function FavoritesScreen() {
  const { l, isRtl } = useI18n();
  const client = useQueryClient();
  const favorites = useQuery({ queryKey: ['favorites', isRtl], queryFn: () => api<{ data: FavoriteItem[] }>(`/favorites?locale=${isRtl ? 'ar' : 'en'}`) });
  const remove = useMutation({ mutationFn: (favorite: FavoriteItem) => api(`/favorites/${favorite.type}/${favorite.id}`, { method: 'DELETE' }), onSuccess: () => client.invalidateQueries({ queryKey: ['favorites'] }) });
  return <Screen>
    <Text style={[styles.title, { textAlign: isRtl ? 'right' : 'left' }]}>{l('المفضلة', 'Favorites')}</Text>
    {!favorites.isLoading && !favorites.data?.data.length ? <Text style={[styles.empty, { textAlign: isRtl ? 'right' : 'left' }]}>{l('احفظ القصص والمنتجات التي تحبها لتجدها هنا.', 'Save stories and products you love to find them here.')}</Text> : null}
    <View style={styles.list}>{favorites.data?.data.map(favorite => <View style={styles.item} key={`${favorite.type}:${favorite.id}`}><ProductCard item={favorite.item} /><Button title={l('إزالة من المفضلة', 'Remove from favorites')} variant="ghost" disabled={remove.isPending} onPress={() => remove.mutate(favorite)} /></View>)}</View>
  </Screen>;
}

const styles = StyleSheet.create({ title: { color: colors.text, fontSize: 29, fontWeight: '900', marginTop: 28 }, empty: { color: colors.muted, lineHeight: 23 }, list: { gap: 18 }, item: { alignItems: 'center', gap: 6 } });
