#!/usr/bin/env node

const crypto = require('crypto');
const fs = require('fs');
const WordPressBuildTool = require('./build');

function sha256(file) {
  return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
}

async function verifyReproducibleBuild() {
  const builder = new WordPressBuildTool();
  const firstArchive = await builder.build();
  const firstHash = sha256(firstArchive);
  const secondArchive = await builder.build();
  const secondHash = sha256(secondArchive);

  if (firstHash !== secondHash) {
    throw new Error(
      `Release archive is not reproducible: ${firstHash} !== ${secondHash}`
    );
  }

  console.log(`Reproducible build verified: ${secondHash}`);
}

verifyReproducibleBuild().catch(error => {
  console.error(`Reproducible build verification failed: ${error.message}`);
  process.exitCode = 1;
});
