@php
    $domain = request()->getHost();
    if (substr($domain, 0, 4) == 'www.') {
        $domain  = substr($domain, 4);
    }
@endphp
<?php echo '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'; ?>
<rss xmlns:g="http://base.google.com/ns/1.0" xmlns:c="http://base.google.com/cns/1.0" version="2.0">
    <channel>
        <title><![CDATA[ {{$domain}} ]]></title>
        <link><![CDATA[ https://.{{$domain}} ]]></link>
        <description><![CDATA[ Your RSS Feed Description ]]></description>
        @foreach ($products as $product)
            @php
                $product_type = '';
                if (!empty($product)) {
                    if (!empty($product->category) && !empty($product->subCategory) && !empty($product->subSubCategory)) {
                        $product_type = $product->category->name . ' > ' . $product->subCategory->name . ' > ' . $product->subSubCategory->name;
                    } elseif (!empty($product->category) && !empty($product->subCategory)) {
                        $product_type = $product->category->name . ' > ' . $product->subCategory->name;
                    } elseif (!empty($product->category)) {
                        $product_type = $product->category->name;
                    }
                }
            @endphp
            <item>
                <g:id>{{ $product->product_code }}</g:id>
                <g:title>{{ $product->name ?? 'No title available' }}</g:title>
                <g:description>{{ strip_tags($product->details ?? 'No description available') }}</g:description>
                <g:item_group_id>{{ $product->product_code ?? 'N/A' }}</g:item_group_id>
                <g:link>{{ url('product/' . $product->slug) }}</g:link>
                <g:product_type>{{ strip_tags($product_type) }}</g:product_type>
                <fb_product_category>{{ $product->category->fb_product_category ?? 'N/A' }}</fb_product_category>
                @if (!empty($product->productImage) && count($product->productImage) > 0)
                    <g:image_link>{{ asset('storage/' . $product->productImage[0]->image) }}</g:image_link>
                @endif
                <g:condition>new</g:condition>
                <g:availability>in stock</g:availability>
                <g:price>{{ $product->price ?? '0.00' }}</g:price>
                <g:sale_price>{{ $product->sale_price ?? '0.00' }}</g:sale_price>
                <g:google_product_category>{{ $product->category->google_product_category ?? 'N/A' }}</g:google_product_category>
                <g:brand>{{ $domain }}</g:brand>
                <g:identifier_exists>No</g:identifier_exists>
                @if (!empty($product->productImage) && count($product->productImage) > 0)
                    @foreach ($product->productImage as $img)
                        <g:additional_image_link>{{ asset('storage/' . $img->image) }}</g:additional_image_link>
                    @endforeach
                @endif
            </item>
        @endforeach
    </channel>
</rss>
