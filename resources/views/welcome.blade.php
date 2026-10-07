<x-front-layout>
    <x-slot name="pageTitle">{{ setting('seo_home_title', 'HeroKid — عالم طفلك من قصص ومنتجات وأنشطة') }}</x-slot>
    <x-slot name="pageDescription">{{ setting('seo_home_description', 'قصص ومنتجات وأنشطة تجعل يوم طفلك أحلى. اكتشف عالم HeroKid المخصص لطفلك.') }}</x-slot>
    @include('front.home-seo')
    @include('front.homepage')
</x-front-layout>
