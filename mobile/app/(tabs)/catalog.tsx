import { useInfiniteQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { api } from '@/src/api/client';
import type { PaginatedCatalog } from '@/src/api/types';
import { ProductCard } from '@/src/components/ProductCard';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';

type TypeFilter='all'|'stories'|'activities'|'products';
type SortFilter='featured'|'newest'|'price_asc'|'price_desc';

export default function CatalogScreen() {
  const {locale,l}=useI18n();
  const [search,setSearch]=useState('');
  const [type,setType]=useState<TypeFilter>('all');
  const [sort,setSort]=useState<SortFilter>('featured');
  const [age,setAge]=useState('');
  const query=useInfiniteQuery({
    queryKey:['catalog',locale,search,type,sort,age],
    initialPageParam:1,
    queryFn:({pageParam})=>api<PaginatedCatalog>(`/catalog?per_page=20&page=${pageParam}&locale=${locale}&q=${encodeURIComponent(search)}&type=${type}&sort=${sort}${age?`&age=${encodeURIComponent(age)}`:''}`),
    getNextPageParam:last=>last.meta.current_page<last.meta.last_page?last.meta.current_page+1:undefined,
  });
  const items=query.data?.pages.flatMap(page=>page.data)??[];
  const ages=query.data?.pages[0]?.meta.filters?.age_ranges??[];
  return <View style={styles.screen}><View style={styles.header}><Text style={styles.title}>{l('متجر HeroKid','HeroKid Shop')}</Text><TextInput accessibilityLabel={l('البحث في المتجر','Search the shop')} value={search} onChangeText={setSearch} placeholder={l('ابحث بالعمر أو الاهتمام أو المنتج','Search by age, interest, or product')} placeholderTextColor="#94A3B8" style={styles.search} textAlign={locale==='ar'?'right':'left'}/><ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>{([['all',l('الكل','All')],['stories',l('القصص','Stories')],['activities',l('الأنشطة','Activities')],['products',l('المنتجات','Products')]]as [TypeFilter,string][]).map(([value,label])=><Chip key={value} label={label} active={type===value} onPress={()=>setType(value)}/>)}</ScrollView><ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>{([['featured',l('المميزة','Featured')],['newest',l('الأحدث','Newest')],['price_asc',l('السعر: الأقل','Price: low to high')],['price_desc',l('السعر: الأعلى','Price: high to low')]]as [SortFilter,string][]).map(([value,label])=><Chip key={value} label={label} active={sort===value} onPress={()=>setSort(value)}/>)}</ScrollView>{ages.length?<ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}><Chip label={l('كل الأعمار','All ages')} active={!age} onPress={()=>setAge('')}/>{ages.map(value=><Chip key={value} label={value} active={age===value} onPress={()=>setAge(value)}/>)}</ScrollView>:null}</View>{query.isLoading?<ActivityIndicator style={styles.loader} size="large" color={colors.primary}/>:<FlatList data={items} keyExtractor={item=>item.id} renderItem={({item})=><ProductCard item={item}/>} contentContainerStyle={styles.list} onEndReached={()=>{if(query.hasNextPage&&!query.isFetchingNextPage)void query.fetchNextPage()}} onEndReachedThreshold={0.4} ListFooterComponent={query.isFetchingNextPage?<ActivityIndicator color={colors.primary}/>:null} ListEmptyComponent={<Text style={styles.empty}>{l('لا توجد نتائج مطابقة.','No matching results.')}</Text>}/>}</View>;
}
function Chip({label,active,onPress}:{label:string;active:boolean;onPress:()=>void}){return <Pressable accessibilityLabel={label} accessibilityRole="button" accessibilityState={{selected:active}} onPress={onPress} style={[styles.chip,active&&styles.chipActive]}><Text style={[styles.chipText,active&&styles.chipTextActive]}>{label}</Text></Pressable>}
const styles=StyleSheet.create({screen:{flex:1,backgroundColor:colors.background},header:{padding:20,paddingTop:58,gap:12},title:{textAlign:'right',color:colors.text,fontSize:28,fontWeight:'900'},search:{height:52,backgroundColor:'#fff',borderColor:colors.border,borderWidth:1,borderRadius:radii.md,paddingHorizontal:15,fontSize:15},chips:{gap:8},chip:{paddingHorizontal:13,paddingVertical:9,borderRadius:radii.pill,backgroundColor:'#fff',borderWidth:1,borderColor:colors.border},chipActive:{backgroundColor:colors.primary,borderColor:colors.primary},chipText:{color:colors.text,fontWeight:'700'},chipTextActive:{color:'#fff'},loader:{marginTop:50},list:{padding:20,paddingTop:0,gap:16,alignItems:'center'},empty:{color:colors.muted,textAlign:'center',padding:30}});
