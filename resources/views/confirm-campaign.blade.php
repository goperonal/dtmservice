<div class="space-y-4">
    <h2 class="text-lg font-semibold text-gray-800">Konfirmasi Campaign</h2>

    <table class="w-full text-sm border border-gray-200 rounded-lg">
        <tbody class="divide-y divide-gray-200">
            <tr>
                <td class="px-4 py-2 font-medium text-gray-600">Nama Campaign</td>
                <td class="px-4 py-2 text-gray-800">{{ $campaignName }}</td>
            </tr>
            <tr>
                <td class="px-4 py-2 font-medium text-gray-600">Kategori Template</td>
                <td class="px-4 py-2 text-gray-800">{{ $category }}</td>
            </tr>
            <tr>
                <td class="px-4 py-2 font-medium text-gray-600">Jumlah Recipient</td>
                <td class="px-4 py-2 text-gray-800">{{ number_format($recipients) }}</td>
            </tr>
            <tr>
                <td class="px-4 py-2 font-medium text-gray-600">Harga per Recipient</td>
                <td class="px-4 py-2 text-gray-800">Rp {{ number_format($price, 2, ',', '.') }}</td>
            </tr>
            <tr class="bg-gray-50">
                <td class="px-4 py-2 font-semibold text-gray-700">Total Estimasi</td>
                <td class="px-4 py-2 font-bold text-green-700">
                    Rp {{ number_format($total, 2, ',', '.') }}
                </td>
            </tr>
        </tbody>
    </table>

    <p class="text-sm text-gray-500">
        Pastikan data campaign sudah benar. Setelah dikonfirmasi, campaign akan tersimpan dan pesan siap dikirim.
    </p>
</div>
