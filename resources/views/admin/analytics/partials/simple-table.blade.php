<div class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
    <div class="border-b border-gray-100 px-5 py-4">
        <h3 class="text-right text-lg font-black text-gray-900">{{ $title }}</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-100 text-right text-sm">
            <thead class="bg-gray-50">
                <tr>
                    @foreach($headers as $header)
                        <th class="px-4 py-3 text-xs font-black uppercase text-gray-500">{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($rows as $row)
                    <tr>
                        @foreach($row as $index => $cell)
                            <td class="px-4 py-3 {{ $index === 0 ? 'font-bold' : '' }} {{ (($ltrColumn ?? null) === $index) ? 'text-xs text-gray-500' : '' }}" @if(($ltrColumn ?? null) === $index) dir="ltr" @endif>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($headers) }}" class="px-4 py-8 text-center text-sm font-bold text-gray-400">
                            لا توجد بيانات متاحة لهذه الفترة.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
